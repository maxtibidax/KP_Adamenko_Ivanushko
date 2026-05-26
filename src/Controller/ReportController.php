<?php

namespace App\Controller;

use App\Service\OrderService;
use App\Service\ReportService;
use Doctrine\DBAL\Connection;
use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reports', name: 'reports_')]
class ReportController extends AbstractController
{
    #[Route('/orders', name: 'orders', methods: ['GET'])]
    public function orders(Request $request, ReportService $reportService): Response
    {
        $filters = $this->filters($request);
        $report = $reportService->buildReport($filters);
        $format = (string) $request->query->get('format', 'html');

        if ($format === 'pdf') {
            $html = $this->renderView('reports/orders_pdf.html.twig', ['filters' => $filters, 'report' => $report]);
            $dompdf = new Dompdf(['defaultFont' => 'DejaVu Sans']);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            return new Response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="orders-report.pdf"',
            ]);
        }

        if ($format === 'xlsx') {
            return $this->ordersXlsx($report);
        }

        return $this->render('reports/orders.html.twig', [
            'filters' => $filters,
            'report' => $report,
            'statuses' => OrderService::STATUSES,
            'event_types' => OrderService::EVENT_TYPES,
        ]);
    }

    #[Route('/profitability', name: 'profitability', methods: ['GET'])]
    public function profitability(Request $request, ReportService $reportService, Connection $connection): Response
    {
        $filters = $this->profitabilityFilters($request);

        // Resolve manager name for display in PDF
        if ($filters['manager_id'] !== '') {
            $managerRow = $connection->fetchAssociative(
                'SELECT manager_full_name FROM manager WHERE manager_id = :id',
                ['id' => (int) $filters['manager_id']]
            );
            $filters['manager_name'] = $managerRow ? $managerRow['manager_full_name'] : '';
        } else {
            $filters['manager_name'] = '';
        }

        $report = $reportService->buildProfitabilityReport($filters);
        $format = (string) $request->query->get('format', 'html');

        if ($format === 'pdf') {
            $html = $this->renderView('reports/profitability_pdf.html.twig', [
                'filters' => $filters,
                'report' => $report['items'],
                'total_overall_profit' => $report['total_profit'],
                'total_sold' => $report['total_sold'],
            ]);
            $dompdf = new Dompdf(['defaultFont' => 'DejaVu Sans']);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            return new Response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="profitability-report.pdf"',
            ]);
        }

        if ($format === 'xlsx') {
            return $this->profitabilityXlsx($report);
        }

        return $this->render('reports/profitability.html.twig', [
            'filters' => $filters,
            'report' => $report['items'],
            'total_overall_profit' => $report['total_profit'],
            'total_sold' => $report['total_sold'],
            'managers' => $connection->fetchAllAssociative('SELECT manager_id, manager_full_name FROM manager ORDER BY manager_full_name'),
            'price_categories' => $connection->fetchFirstColumn("SELECT DISTINCT price_category FROM dish WHERE price_category IS NOT NULL ORDER BY price_category"),
        ]);
    }

    #[Route('/warehouse', name: 'warehouse', methods: ['GET'])]
    public function warehouse(Request $request, ReportService $reportService, Connection $connection): Response
    {
        $filters = $this->warehouseFilters($request);
        $report = $reportService->buildWarehouseReport($filters);
        $format = (string) $request->query->get('format', 'html');

        if ($format === 'pdf') {
            $html = $this->renderView('reports/warehouse_pdf.html.twig', ['report' => $report, 'filters' => $filters]);
            $dompdf = new Dompdf(['defaultFont' => 'DejaVu Sans']);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            return new Response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="warehouse-report.pdf"',
            ]);
        }

        if ($format === 'xlsx') {
            return $this->warehouseXlsx($report);
        }

        return $this->render('reports/warehouse.html.twig', [
            'report' => $report,
            'filters' => $filters,
            'suppliers' => $connection->fetchAllAssociative('SELECT supplier_id, supplier_name FROM supplier ORDER BY supplier_name'),
        ]);
    }

    private function filters(Request $request): array
    {
        return [
            'date_from' => (string) $request->query->get('date_from', date('Y-m-01')),
            'date_to' => (string) $request->query->get('date_to', date('Y-m-d')),
            'status' => (string) $request->query->get('status', ''),
            'event_type' => (string) $request->query->get('event_type', ''),
        ];
    }

    private function warehouseFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query->get('q', '')),
            'low' => (string) $request->query->get('low', ''),
            'status' => (string) $request->query->get('status', ''),
            'date_from' => (string) $request->query->get('date_from', ''),
            'date_to' => (string) $request->query->get('date_to', ''),
            'supplier_id' => (string) $request->query->get('supplier_id', ''),
        ];
    }

    private function profitabilityFilters(Request $request): array
    {
        return [
            'date_from' => (string) $request->query->get('date_from', date('Y-m-01')),
            'date_to' => (string) $request->query->get('date_to', date('Y-m-d')),
            'manager_id' => (string) $request->query->get('manager_id', ''),
            'price_category' => (string) $request->query->get('price_category', ''),
            'only_sold' => (string) $request->query->get('only_sold', ''),
        ];
    }

    private function ordersXlsx(array $report): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Заказы');
        $sheet->fromArray(['Дата', 'Клиент', 'Менеджер', 'Тип', 'Статус', 'Стоимость', 'Предоплата', 'Оплачен'], null, 'A1');

        $rowNumber = 2;
        foreach ($report['orders'] as $order) {
            $sheet->fromArray([
                $order['event_date'],
                $order['client_full_name'],
                $order['manager_full_name'],
                $order['event_type'],
                $order['status'],
                (float) $order['total_cost'],
                (float) $order['prepayment_amount'],
                $order['is_fully_paid'] ? 'Да' : 'Нет',
            ], null, 'A'.$rowNumber);
            $rowNumber++;
        }

        $rowNumber++;
        $sheet->fromArray(['Итого', '', '', '', '', (float) $report['total_revenue'], (float) $report['total_prepayment'], 'К оплате: '.number_format((float) ($report['total_revenue'] - $report['total_prepayment']), 2, ',', ' ')], null, 'A'.$rowNumber);
        $sheet->getStyle('A'.$rowNumber.':H'.$rowNumber)->getFont()->setBold(true);

        // Вспомогательная таблица с данными по типам мероприятий в колонках J и K сохраняется
        $sheet->fromArray(['Тип мероприятия', 'Выручка'], null, 'J1');
        $chartRow = 2;
        foreach ($report['by_type'] as $row) {
            $sheet->fromArray([$row['event_type'], (float) $row['revenue']], null, 'J'.$chartRow);
            $chartRow++;
        }
        $chartStartRow = $rowNumber + 3;
        $this->addBarChart($sheet, 'ordersRevenueChart', 'Выручка по типам мероприятий', 'J', 'K', $chartRow - 1, 'J'.$chartStartRow, 'N'.($chartStartRow + 14));

        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return new StreamedResponse(static function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true); // Отключаем рендеринг диаграмм в итоговом файле Excel
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="orders-report.xlsx"',
        ]);
    }

    private function warehouseXlsx(array $report): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Склад');
        $sheet->fromArray(['Продукт', 'Остаток', 'Минимум', 'Последнее пополнение', 'Состояние'], null, 'A1');

        $rowNumber = 2;
        foreach ($report['stock'] as $item) {
            $sheet->fromArray([
                $item['product_name'],
                (float) $item['quantity'],
                (float) $item['min_quantity'],
                $item['last_restock_date'],
                $item['is_low'] ? 'Нужно пополнить' : 'Достаточно',
            ], null, 'A'.$rowNumber);
            $rowNumber++;
        }

        $rowNumber++;
        $sheet->fromArray(['Итого', (float) $report['total_quantity'], '', '', 'Низких остатков: '.$report['low_count']], null, 'A'.$rowNumber);
        $sheet->getStyle('A'.$rowNumber.':E'.$rowNumber)->getFont()->setBold(true);

        $sheet->setTitle('Склад');
        $requestsSheet = $spreadsheet->createSheet();
        $requestsSheet->setTitle('Поставки');
        $requestsSheet->fromArray(['Дата', 'Поставщик', 'Менеджер', 'Состав', 'Статус'], null, 'A1');
        $rowNumber = 2;
        foreach ($report['requests'] as $request) {
            $requestsSheet->fromArray([
                $request['request_date'],
                $request['supplier_name'],
                $request['manager_full_name'],
                $request['products'],
                $request['status'],
            ], null, 'A'.$rowNumber);
            $rowNumber++;
        }

        foreach ([$sheet, $requestsSheet] as $worksheet) {
            foreach (range('A', 'E') as $column) {
                $worksheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        $requestsSheet->fromArray(['Поставщик', 'Количество'], null, 'G1');
        $chartRow = 2;
        foreach ($report['by_supplier'] as $row) {
            $requestsSheet->fromArray([$row['supplier_name'], (float) $row['total_products']], null, 'G'.$chartRow);
            $chartRow++;
        }
        $requestsSheetChartStartRow = $rowNumber + 2;
        $this->addBarChart($requestsSheet, 'supplierProductsChart', 'Объем поставок по поставщикам', 'G', 'H', $chartRow - 1, 'G'.$requestsSheetChartStartRow, 'K'.($requestsSheetChartStartRow + 14));
        foreach (range('G', 'H') as $column) {
            $requestsSheet->getColumnDimension($column)->setAutoSize(true);
        }

        return new StreamedResponse(static function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="warehouse-report.xlsx"',
        ]);
    }

    private function profitabilityXlsx(array $report): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Прибыльность');
        $sheet->fromArray(['Блюдо', 'Категория', 'Себестоимость', 'Цена продажи', 'Прибыль с порции', 'Продано порций', 'Общая прибыль'], null, 'A1');

        $rowNumber = 2;
        foreach ($report['items'] as $item) {
            $sheet->fromArray([
                $item['dish_name'],
                $item['price_category'],
                (float) $item['cost_price'],
                (float) $item['sale_price'],
                (float) $item['profit'],
                (float) $item['total_sold'],
                (float) $item['total_profit'],
            ], null, 'A'.$rowNumber);
            $rowNumber++;
        }

        $rowNumber++;
        $sheet->fromArray(['Итого', '', '', '', '', (float) $report['total_sold'], (float) $report['total_profit']], null, 'A'.$rowNumber);
        $sheet->getStyle('A'.$rowNumber.':G'.$rowNumber)->getFont()->setBold(true);

        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return new StreamedResponse(static function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="profitability-report.xlsx"',
        ]);
    }

    private function addBarChart($sheet, string $name, string $title, string $labelColumn, string $valueColumn, int $lastRow, string $topLeft, string $bottomRight): void
    {
        if ($lastRow < 2) {
            return;
        }

        $sheetTitle = $sheet->getTitle();
        $labelsRange = sprintf("'%s'!\$%s\$2:\$%s\$%d", $sheetTitle, $labelColumn, $labelColumn, $lastRow);
        $valuesRange = sprintf("'%s'!\$%s\$2:\$%s\$%d", $sheetTitle, $valueColumn, $valueColumn, $lastRow);
        $seriesLabelRange = sprintf("'%s'!\$%s\$1", $sheetTitle, $valueColumn);
        $labels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $labelsRange, null, $lastRow - 1)];
        $values = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $valuesRange, null, $lastRow - 1)];
        $seriesLabels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $seriesLabelRange, null, 1)];

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($values) - 1),
            $seriesLabels,
            $labels,
            $values
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $chart = new Chart($name, new Title($title), new Legend(Legend::POSITION_RIGHT, null, false), new PlotArea(null, [$series]));
        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);
        $sheet->addChart($chart);
    }
}
