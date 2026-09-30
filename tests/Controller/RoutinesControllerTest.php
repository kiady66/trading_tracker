<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RoutinesControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()->executeStatement('TRUNCATE "user" RESTART IDENTITY CASCADE');
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/routines');
        $this->assertResponseRedirects('http://localhost/login');
    }

    public function testPageRendersEveryRoutineTab(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('bob@test.com')
            ->setPassword('irrelevant-for-loginUser')
            ->setDisplayName('bob');
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user, 'main');
        $crawler = $this->client->request('GET', '/routines');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['wk', 'day', 'al', 'nw', 'ref'],
            $crawler->filter('[data-routines-target="tab"]')->each(fn ($node) => $node->attr('id')),
        );
        $this->assertCount(5, $crawler->filter('[data-routines-target="tabButton"]'));
        $this->assertGreaterThan(30, $crawler->filter('.rt-item input[type=checkbox]')->count());
        $this->assertSelectorTextContains('.nav-center', 'Routines');
    }
}
