<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class ReportService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function buildReport(array $filters): array
    {
        $where = ['o.event_date BETWEEN :date_from AND :date_to'];
        $params = ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to']];

        if ($filters['status'] !== '') {
            $where[] = 'o.status = :status';
            $params['status'] = $filters['status'];
        }
        if ($filters['event_type'] !== '') {
            $where[] = 'o.event_type = :event_type';
            $params['event_type'] = $filters['event_type'];
        }

        $whereFiltersSql = 'WHERE '.implode(' AND ', $where);
        $orders = $this->connection->fetchAllAssociative(
            "SELECT o.event_date, o.status, o.event_type, o.total_cost, o.prepayment_amount, o.is_fully_paid,
                    c.client_full_name, m.manager_full_name
             FROM orders o
             JOIN client c ON c.client_id = o.client_id
             JOIN manager m ON m.manager_id = o.manager_id
             $whereFiltersSql
             ORDER BY o.event_date DESC",
            $params
        );

        $byType = $this->connection->fetchAllAssociative(
            "SELECT o.event_type, SUM(o.total_cost) AS revenue
             FROM orders o
             $whereFiltersSql AND o.status <> :cancelled
             GROUP BY o.event_type
             ORDER BY revenue DESC",
            array_merge($params, ['cancelled' => OrderService::STATUS_CANCELLED])
        );

        $totalRevenue = 0.0;
        $totalPrepayment = 0.0;
        foreach ($orders as $order) {
            $totalRevenue += (float) $order['total_cost'];
            $totalPrepayment += (float) $order['prepayment_amount'];
        }

        $byTypeWithCount = $this->connection->fetchAllAssociative(
            "SELECT o.event_type, COUNT(*) AS orders_count, SUM(o.total_cost) AS revenue
             FROM orders o
             $whereFiltersSql AND o.status <> :cancelled
             GROUP BY o.event_type
             ORDER BY revenue DESC",
            array_merge($params, ['cancelled' => OrderService::STATUS_CANCELLED])
        );

        return [
            'orders' => $orders,
            'by_type' => $byTypeWithCount,
            'total_revenue' => $totalRevenue,
            'total_prepayment' => $totalPrepayment,
            'total_count' => count($orders),
            'max_revenue' => max([1, ...array_map(static fn (array $row): float => (float) $row['revenue'], $byTypeWithCount)]),
        ];
    }

    public function buildWarehouseReport(array $filters): array
    {
        $stockWhere = [];
        $stockParams = [];
        if ($filters['q'] !== '') {
            $stockWhere[] = 'p.product_name ILIKE :q';
            $stockParams['q'] = '%'.$filters['q'].'%';
        }
        if ($filters['low'] === '1') {
            $stockWhere[] = 's.quantity <= s.min_quantity';
        }
        $stockWhereSql = $stockWhere === [] ? '' : 'WHERE '.implode(' AND ', $stockWhere);

        $stock = $this->connection->fetchAllAssociative(
            "SELECT p.product_name, s.quantity, s.min_quantity, s.last_restock_date,
                    CASE WHEN s.quantity <= s.min_quantity THEN TRUE ELSE FALSE END AS is_low
             FROM product_stock s
             JOIN product p ON p.product_id = s.product_id
             $stockWhereSql
             ORDER BY is_low DESC, p.product_name",
            $stockParams
        );

        $requestWhere = [];
        $requestParams = [];
        if (($filters['supplier_id'] ?? '') !== '') {
            $requestWhere[] = 'sr.supplier_id = :supplier_id';
            $requestParams['supplier_id'] = (int) $filters['supplier_id'];
        }
        if ($filters['status'] !== '') {
            $requestWhere[] = 'sr.status = :status';
            $requestParams['status'] = $filters['status'];
        }
        if ($filters['date_from'] !== '') {
            $requestWhere[] = 'sr.request_date >= :date_from';
            $requestParams['date_from'] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $requestWhere[] = 'sr.request_date <= :date_to';
            $requestParams['date_to'] = $filters['date_to'];
        }
        $requestWhereSql = $requestWhere === [] ? '' : 'WHERE '.implode(' AND ', $requestWhere);

        $requests = $this->connection->fetchAllAssociative(
            "SELECT sr.request_date, sr.status, s.supplier_name, m.manager_full_name,
                    COALESCE(SUM(rd.products_number), 0) AS total_products,
                    COALESCE(string_agg(p.product_name || ' x ' || rd.products_number, ', ' ORDER BY p.product_name), '') AS products
             FROM supplier_request sr
             JOIN supplier s ON s.supplier_id = sr.supplier_id
             JOIN manager m ON m.manager_id = sr.manager_id
             LEFT JOIN request_details rd ON rd.request_id = sr.request_id
             LEFT JOIN product p ON p.product_id = rd.product_id
             $requestWhereSql
             GROUP BY sr.request_id, s.supplier_name, m.manager_full_name
             ORDER BY sr.request_date DESC, sr.request_id DESC
             LIMIT 30",
            $requestParams
        );

        $bySupplier = $this->connection->fetchAllAssociative(
            "SELECT s.supplier_name, COUNT(sr.request_id) AS requests_count, COALESCE(SUM(rd.products_number), 0) AS total_products
             FROM supplier s
             LEFT JOIN supplier_request sr ON sr.supplier_id = s.supplier_id
             LEFT JOIN request_details rd ON rd.request_id = sr.request_id
             ".($requestWhere === [] ? '' : 'WHERE '.implode(' AND ', array_map(static fn (string $clause): string => str_replace('sr.', 'sr.', $clause), $requestWhere)))."
             GROUP BY s.supplier_id, s.supplier_name
             ORDER BY total_products DESC, s.supplier_name",
            $requestParams
        );

        $maxSupplierTotal = 1;
        foreach ($bySupplier as $row) {
            $val = (float) $row['total_products'];
            if ($val > $maxSupplierTotal) {
                $maxSupplierTotal = $val;
            }
        }

        $totalQuantity = 0.0;
        $lowCount = 0;
        foreach ($stock as $row) {
            $totalQuantity += (float) $row['quantity'];
            if ($row['is_low']) {
                $lowCount++;
            }
        }

        $pendingRequests = 0;
        $receivedRequests = 0;
        foreach ($requests as $row) {
            if ($row['status'] === 'Получено') {
                $receivedRequests++;
            } else {
                $pendingRequests++;
            }
        }

        return [
            'stock' => $stock,
            'requests' => $requests,
            'by_supplier' => $bySupplier,
            'max_supplier_total' => $maxSupplierTotal,
            'products_count' => count($stock),
            'low_count' => $lowCount,
            'total_quantity' => $totalQuantity,
            'pending_requests' => $pendingRequests,
            'received_requests' => $receivedRequests,
        ];
    }

    public function buildProfitabilityReport(array $filters): array
    {
        $joinConditions = [
            'o.order_id = od.order_id',
            "o.status <> :cancelled",
            'o.event_date BETWEEN :date_from AND :date_to',
        ];
        $params = [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'cancelled' => OrderService::STATUS_CANCELLED,
        ];

        if ($filters['manager_id'] !== '') {
            $joinConditions[] = 'o.manager_id = :manager_id';
            $params['manager_id'] = (int) $filters['manager_id'];
        }

        $where = [];
        if ($filters['price_category'] !== '') {
            $where[] = 'd.price_category = :price_category';
            $params['price_category'] = $filters['price_category'];
        }

        $havingSql = '';
        if (($filters['only_sold'] ?? '') === '1') {
            $havingSql = "HAVING COALESCE(SUM(CASE WHEN o.order_id IS NOT NULL THEN od.serving_number ELSE 0 END), 0) > 0";
        }
        $whereFiltersSql = $where === [] ? '' : 'WHERE '.implode(' AND ', $where);
        $ordersJoinSql = implode(' AND ', $joinConditions);

        $items = $this->connection->fetchAllAssociative(
            "SELECT d.dish_name, d.price_category, d.cost_price, d.sale_price, (d.sale_price - d.cost_price) AS profit,
                    COALESCE(SUM(CASE WHEN o.order_id IS NOT NULL THEN od.serving_number ELSE 0 END), 0) AS total_sold,
                    COALESCE(SUM(CASE WHEN o.order_id IS NOT NULL THEN od.serving_number ELSE 0 END) * (d.sale_price - d.cost_price), 0) AS total_profit
             FROM dish d
             LEFT JOIN order_details od ON od.dish_id = d.dish_id
             LEFT JOIN orders o ON $ordersJoinSql
             $whereFiltersSql
             GROUP BY d.dish_id, d.dish_name, d.price_category, d.cost_price, d.sale_price
             $havingSql
             ORDER BY total_profit DESC, total_sold DESC, d.dish_name",
            $params
        );

        $totalProfit = 0.0;
        $totalSold = 0.0;
        foreach ($items as $row) {
            $totalProfit += (float) $row['total_profit'];
            $totalSold += (float) $row['total_sold'];
        }

        return [
            'items' => $items,
            'total_profit' => $totalProfit,
            'total_sold' => $totalSold,
        ];
    }
}
