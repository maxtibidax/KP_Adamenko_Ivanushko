<?php

namespace App\Controller;

use App\Service\OrderService;
use App\Service\ErrorMessageFormatter;
use Throwable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/orders', name: 'orders_')]
class OrderController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, OrderService $orders): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'status' => (string) $request->query->get('status', ''),
            'event_type' => (string) $request->query->get('event_type', ''),
            'date_from' => (string) $request->query->get('date_from', ''),
            'date_to' => (string) $request->query->get('date_to', ''),
            'sort' => (string) $request->query->get('sort', 'date'),
            'direction' => (string) $request->query->get('direction', 'desc'),
        ];

        $result = $orders->getOrders($filters, $page, 8);

        return $this->render('orders/index.html.twig', [
            'result' => $result,
            'order_details' => $orders->getDetailsForOrders(array_column($result['items'], 'order_id')),
            'page' => $page,
            'filters' => $filters,
            'clients' => $orders->clients(),
            'managers' => $orders->managers(),
            'dishes' => $orders->activeDishes(),
            'statuses' => OrderService::STATUSES,
            'event_types' => OrderService::EVENT_TYPES,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request, OrderService $orders, ErrorMessageFormatter $errors): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('default', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен.');
            return $this->redirectBack($request);
        }
        try {
            $result = $orders->createComplexOrder($request->request->all());
            $this->addFlash('success', $result['p_message'].' Итог: '.number_format((float) $result['p_total_cost'], 2, ',', ' ').' руб.');
        } catch (Throwable $exception) {
            $this->addFlash('danger', $errors->format($exception));
        }

        return $this->redirectToRoute('orders_index');
    }

    #[Route('/{id}/edit-form', name: 'edit_form', methods: ['GET'])]
    public function editForm(int $id, OrderService $orders): Response
    {
        $order = $orders->getOrder($id);
        if ($order === null) {
            return new Response('Заказ не найден', 404);
        }

        return $this->render('orders/_edit_form.html.twig', [
            'order'        => $order,
            'order_details'=> $orders->getOrderDetails($id),
            'clients'      => $orders->clients(),
            'managers'     => $orders->managers(),
            'dishes'       => $orders->activeDishes(),
            'statuses'     => OrderService::STATUSES,
            'event_types'  => OrderService::EVENT_TYPES,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['POST'])]
    public function edit(int $id, Request $request, OrderService $orders, ErrorMessageFormatter $errors): RedirectResponse
    {
        try {
            $orders->updateOrder($id, $request->request->all());
            $this->addFlash('success', 'Заказ обновлен.');
        } catch (Throwable $exception) {
            $this->addFlash('danger', $errors->format($exception));
        }

        return $this->redirectBack($request);
    }

    #[Route('/{id}/inline', name: 'inline', methods: ['POST'])]
    public function inline(int $id, Request $request, OrderService $orders, ErrorMessageFormatter $errors): RedirectResponse
    {
        try {
            $orders->inlineUpdate($id, (string) $request->request->get('field'), (string) $request->request->get('value'));
            $this->addFlash('success', 'Быстрое изменение сохранено.');
        } catch (Throwable $exception) {
            $this->addFlash('danger', $errors->format($exception));
        }

        return $this->redirectBack($request);
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, OrderService $orders, ErrorMessageFormatter $errors): RedirectResponse
    {
        try {
            $orders->cancelOrder($id);
            $this->addFlash('success', 'Заказ отменен, продукты возвращены на склад.');
        } catch (Throwable $exception) {
            $this->addFlash('danger', $errors->format($exception));
        }

        return $this->redirectBack($request);
    }

    #[Route('/{id}/history', name: 'history', methods: ['GET'])]
    public function history(int $id, \Doctrine\DBAL\Connection $connection): Response
    {
        $orderExists = (bool) $connection->fetchOne("SELECT 1 FROM orders WHERE order_id = :id", ['id' => $id]);
        if (!$orderExists) {
            throw $this->createNotFoundException('Заказ не найден');
        }

        $logs = $connection->fetchAllAssociative(
            "SELECT operation_date, operation_type, description
             FROM operation_log
             WHERE table_name = 'Orders' AND record_id = :id
             ORDER BY operation_date DESC",
            ['id' => $id]
        );

        return $this->render('orders/history.html.twig', [
            'order_id' => $id,
            'order_info' => $connection->fetchAssociative(
                'SELECT o.event_date, o.event_type, c.client_full_name FROM orders o JOIN client c ON c.client_id = o.client_id WHERE o.order_id = :id',
                ['id' => $id]
            ) ?: null,
            'logs' => $logs,
        ]);
    }

    private function redirectBack(Request $request): RedirectResponse
    {
        return new RedirectResponse($request->headers->get('referer') ?: $this->generateUrl('orders_index'));
    }
}
