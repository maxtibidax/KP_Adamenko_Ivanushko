<?php

namespace App\Entity;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class AppUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    private int $id;
    private string $username;
    private string $password;
    private string $fullName;
    /** @var string[] */
    private array $roles;
    private bool $isActive;

    public function __construct(int $id, string $username, string $password, string $fullName, array $roles, bool $isActive = true)
    {
        $this->id = $id;
        $this->username = $username;
        $this->password = $password;
        $this->fullName = $fullName;
        $this->roles = $roles;
        $this->isActive = $isActive;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        if (!in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }
        return array_unique($roles);
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }
}
