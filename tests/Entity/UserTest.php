<?php

namespace App\Tests\Entity;

use App\Entity\Trade;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    public function testConstructorInitializesCreatedAt(): void
    {
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->user->getCreatedAt());
    }

    public function testConstructorInitializesTradesCollection(): void
    {
        $this->assertCount(0, $this->user->getTrades());
    }

    public function testSetAndGetEmail(): void
    {
        $email = 'test@example.com';
        $result = $this->user->setEmail($email);

        $this->assertSame($this->user, $result);
        $this->assertSame($email, $this->user->getEmail());
    }

    public function testGetUserIdentifier(): void
    {
        $email = 'user@example.com';
        $this->user->setEmail($email);

        $this->assertSame($email, $this->user->getUserIdentifier());
    }

    public function testGetUserIdentifierWithNullEmail(): void
    {
        $this->assertSame('', $this->user->getUserIdentifier());
    }

    public function testGetRolesAlwaysIncludesRoleUser(): void
    {
        $roles = $this->user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
    }

    public function testSetAndGetRoles(): void
    {
        $roles = ['ROLE_ADMIN', 'ROLE_MODERATOR'];
        $result = $this->user->setRoles($roles);

        $this->assertSame($this->user, $result);
        $actualRoles = $this->user->getRoles();

        $this->assertContains('ROLE_USER', $actualRoles);
        $this->assertContains('ROLE_ADMIN', $actualRoles);
        $this->assertContains('ROLE_MODERATOR', $actualRoles);
    }

    public function testGetRolesReturnsUniqueValues(): void
    {
        $this->user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $roles = $this->user->getRoles();

        $this->assertCount(2, $roles);
        $this->assertSame($roles, array_unique($roles));
    }

    public function testSetAndGetPassword(): void
    {
        $password = 'hashed_password_123';
        $result = $this->user->setPassword($password);

        $this->assertSame($this->user, $result);
        $this->assertSame($password, $this->user->getPassword());
    }

    public function testSetAndGetPlainPassword(): void
    {
        $plainPassword = 'plain_password_123';
        $result = $this->user->setPlainPassword($plainPassword);

        $this->assertSame($this->user, $result);
        $this->assertSame($plainPassword, $this->user->getPlainPassword());
    }

    public function testEraseCredentials(): void
    {
        $this->user->setPlainPassword('plain_password');
        $this->user->eraseCredentials();

        $this->assertNull($this->user->getPlainPassword());
    }

    public function testAddTrade(): void
    {
        $trade = $this->createMock(Trade::class);
        $trade->expects($this->once())
            ->method('setUser')
            ->with($this->user);

        $result = $this->user->addTrade($trade);

        $this->assertSame($this->user, $result);
        $this->assertCount(1, $this->user->getTrades());
        $this->assertTrue($this->user->getTrades()->contains($trade));
    }

    public function testAddTradeDoesNotDuplicateExistingTrade(): void
    {
        $trade = $this->createMock(Trade::class);
        $trade->expects($this->once())
            ->method('setUser')
            ->with($this->user);

        $this->user->addTrade($trade);
        $this->user->addTrade($trade);

        $this->assertCount(1, $this->user->getTrades());
    }

    public function testRemoveTrade(): void
    {
        $trade = $this->createMock(Trade::class);
        $trade->expects($this->exactly(2))
            ->method('setUser')
            ->with($this->callback(function ($user) {
                return $user === $this->user || $user === null;
            }));
        $trade->expects($this->once())
            ->method('getUser')
            ->willReturn($this->user);

        $this->user->addTrade($trade);
        $result = $this->user->removeTrade($trade);

        $this->assertSame($this->user, $result);
        $this->assertCount(0, $this->user->getTrades());
    }

    public function testRemoveTradeWhenTradeNotInCollection(): void
    {
        $trade = $this->createMock(Trade::class);
        $trade->expects($this->never())
            ->method('setUser');

        $result = $this->user->removeTrade($trade);

        $this->assertSame($this->user, $result);
        $this->assertCount(0, $this->user->getTrades());
    }

    public function testRemoveTradeDoesNotUnsetWhenTradeUserMismatch(): void
    {
        $trade = $this->createMock(Trade::class);
        $otherUser = new User();

        $trade->expects($this->once())
            ->method('setUser')
            ->with($this->user);
        $trade->expects($this->once())
            ->method('getUser')
            ->willReturn($otherUser);

        $this->user->addTrade($trade);
        $this->user->removeTrade($trade);

        $this->assertCount(0, $this->user->getTrades());
    }

    public function testSetAndGetCreatedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 12:00:00');
        $result = $this->user->setCreatedAt($createdAt);

        $this->assertSame($this->user, $result);
        $this->assertSame($createdAt, $this->user->getCreatedAt());
    }

    public function testIsAdminReturnsTrueWhenUserHasAdminRole(): void
    {
        $this->user->setRoles([User::ROLE_ADMIN]);

        $this->assertTrue($this->user->isAdmin());
    }

    public function testIsAdminReturnsFalseWhenUserDoesNotHaveAdminRole(): void
    {
        $this->user->setRoles([User::ROLE_MODERATOR]);

        $this->assertFalse($this->user->isAdmin());
    }

    public function testIsPremiumReturnsTrueWhenUserIsAdmin(): void
    {
        $this->user->setRoles([User::ROLE_ADMIN]);

        $this->assertTrue($this->user->isPremium());
    }

    public function testIsPremiumReturnsFalseWhenUserIsNotAdmin(): void
    {
        $this->user->setRoles([User::ROLE_TRADER]);

        $this->assertFalse($this->user->isPremium());
    }

    public function testRoleConstants(): void
    {
        $this->assertSame('ROLE_ADMIN', User::ROLE_ADMIN);
        $this->assertSame('ROLE_MODERATOR', User::ROLE_MODERATOR);
        $this->assertSame('ROLE_TRADER', User::ROLE_TRADER);
    }

    public function testGetIdReturnsNullForNewEntity(): void
    {
        $this->assertNull($this->user->getId());
    }
}