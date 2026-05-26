<?php

namespace App\Security;

use App\Entity\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class DbUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, username, password, full_name, roles, is_active FROM app_user WHERE username = :username AND is_active = TRUE',
            ['username' => $identifier]
        );

        if (!$row) {
            throw new UserNotFoundException(sprintf('Пользователь "%s" не найден.', $identifier));
        }

        return $this->hydrateUser($row);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AppUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === AppUser::class || is_subclass_of($class, AppUser::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof AppUser) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE app_user SET password = :password WHERE id = :id',
            ['password' => $newHashedPassword, 'id' => $user->getId()]
        );
    }

    /**
     * Create a new user in the database.
     */
    public function createUser(string $username, string $hashedPassword, string $fullName, array $roles = ['ROLE_USER']): void
    {
        $this->connection->executeStatement(
            'INSERT INTO app_user (username, password, full_name, roles) VALUES (:username, :password, :full_name, :roles::jsonb)',
            [
                'username' => $username,
                'password' => $hashedPassword,
                'full_name' => $fullName,
                'roles' => json_encode(array_values($roles)),
            ]
        );
    }

    public function usernameExists(string $username): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM app_user WHERE username = :username',
            ['username' => $username]
        );
    }

    /**
     * @return AppUser[]
     */
    public function allUsers(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, username, password, full_name, roles, is_active FROM app_user ORDER BY username'
        );

        return array_map(fn (array $row) => $this->hydrateUser($row), $rows);
    }

    private function hydrUser(array $row): AppUser
    {
        $roles = json_decode($row['roles'], true) ?? [];

        return new AppUser(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['password'],
            (string) $row['full_name'],
            $roles,
            (bool) $row['is_active']
        );
    }

    private function hydrateUser(array $row): AppUser
    {
        return $this->hydrUser($row);
    }
}
