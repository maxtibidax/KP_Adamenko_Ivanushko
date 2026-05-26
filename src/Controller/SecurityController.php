<?php

namespace App\Controller;

use App\Entity\AppUser;
use App\Security\DbUserProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', ['last_username' => $lastUsername, 'error' => $error]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route(path: '/register', name: 'app_register')]
    public function register(Request $request, DbUserProvider $userProvider, UserPasswordHasherInterface $passwordHasher): Response
    {
        if ($request->isMethod('POST')) {
            $username = trim((string) $request->request->get('username', ''));
            $fullName = trim((string) $request->request->get('full_name', ''));
            $plainPassword = (string) $request->request->get('password', '');
            $confirmPassword = (string) $request->request->get('confirm_password', '');

            $errors = [];

            if ($username === '') {
                $errors[] = 'Укажите имя пользователя.';
            } elseif (mb_strlen($username) < 3) {
                $errors[] = 'Имя пользователя должно содержать не менее 3 символов.';
            } elseif ($userProvider->usernameExists($username)) {
                $errors[] = 'Пользователь с таким именем уже существует.';
            }

            if ($fullName === '') {
                $errors[] = 'Укажите ваше полное имя (ФИО).';
            }

            if ($plainPassword === '') {
                $errors[] = 'Укажите пароль.';
            } elseif (mb_strlen($plainPassword) < 6) {
                $errors[] = 'Пароль должен содержать не менее 6 символов.';
            }

            if ($plainPassword !== $confirmPassword) {
                $errors[] = 'Пароли не совпадают.';
            }

            if ($errors === []) {
                try {
                    // Create a temporary user object for password hashing
                    $tempUser = new AppUser(0, $username, '', $fullName, ['ROLE_USER']);
                    $hashedPassword = $passwordHasher->hashPassword($tempUser, $plainPassword);

                    $userProvider->createUser($username, $hashedPassword, $fullName, ['ROLE_USER']);

                    $this->addFlash('success', sprintf('Пользователь "%s" успешно зарегистрирован. Теперь вы можете войти.', $username));

                    return $this->redirectToRoute('app_login');
                } catch (\Throwable $e) {
                    $errors[] = 'Произошла ошибка при регистрации. Попробуйте ещё раз.';
                }
            }

            foreach ($errors as $error) {
                $this->addFlash('danger', $error);
            }

            return $this->render('security/register.html.twig', [
                'last_username' => $username,
                'last_fullname' => $fullName,
            ]);
        }

        return $this->render('security/register.html.twig', [
            'last_username' => '',
            'last_fullname' => '',
        ]);
    }
}
