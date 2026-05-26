<?php

namespace App\Controller;

use App\Service\ErrorMessageFormatter;
use App\Service\ReferenceService;
use Throwable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class MenuController extends AbstractController
{
    #[Route('/menu', name: 'menu_index', methods: ['GET'])]
    public function menu(Request $request, ReferenceService $references): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'active' => (string) $request->query->get('active', ''),
            'category' => (string) $request->query->get('category', ''),
        ];

        $result = $references->dishes($filters, $page, 10);

        return $this->render('references/menu.html.twig', [
            'result' => $result,
            'page' => $page,
            'filters' => $filters,
            'products' => $references->products(),
            'recipes' => $references->recipesByDish(array_map(static fn (array $d): int => (int) $d['dish_id'], $result['items'])),
        ]);
    }

    #[Route('/menu/create', name: 'menu_create', methods: ['POST'])]
    public function createDish(Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->createDish($request->request->all()), 'Блюдо создано.', 'menu_index');
    }

    #[Route('/menu/{id}/edit', name: 'menu_edit', methods: ['POST'])]
    public function editDish(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->updateDish($id, $request->request->all()), 'Блюдо обновлено.', 'menu_index');
    }

    #[Route('/menu/{id}/toggle', name: 'menu_toggle', methods: ['POST'])]
    public function toggleDish(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        $isActive = (bool) $request->request->get('is_active', false);
        return $this->handle($request, $references, $errors, static fn () => $references->toggleDish($id, $isActive), 'Статус блюда изменен.', 'menu_index');
    }

    #[Route('/menu/{id}/delete', name: 'menu_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteDish(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->deleteDish($id), 'Блюдо удалено.', 'menu_index');
    }

    #[Route('/menu/{id}/recipe', name: 'menu_recipe_save', methods: ['POST'])]
    public function saveRecipeItem(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->upsertRecipeItem($id, $request->request->all()), 'Рецептура обновлена.', 'menu_index');
    }

    #[Route('/menu/{dishId}/recipe/{productId}/delete', name: 'menu_recipe_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteRecipeItem(int $dishId, int $productId, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->deleteRecipeItem($dishId, $productId), 'Строка рецептуры удалена.', 'menu_index');
    }

    private function handle(Request $request, ReferenceService $references, ErrorMessageFormatter $errors, callable $operation, string $success, string $fallbackRoute): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('default', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен.');
            return new RedirectResponse($request->headers->get('referer') ?: $this->generateUrl($fallbackRoute));
        }
        try {
            $operation();
            $this->addFlash('success', $success);
        } catch (Throwable $exception) {
            $this->addFlash('danger', $errors->format($exception));
        }

        return new RedirectResponse(
            $request->headers->get('referer')
            ?: $this->generateUrl($fallbackRoute)
        );
    }
}
