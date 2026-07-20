<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Firebase\FirebaseTokenVerifier;
use App\Security\Firebase\InvalidFirebaseTokenException;
use App\Security\Firebase\VerifiedFirebaseToken;
use App\Security\FirebaseAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Le vérificateur de token est remplacé par un double : la vérification réelle
 * télécharge les clés publiques Google, ce qui rendrait la suite dépendante du réseau.
 */
class FirebaseAuthenticatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->users = static::getContainer()->get(UserRepository::class);
        $this->em->getConnection()->executeStatement('TRUNCATE "user" RESTART IDENTITY CASCADE');
    }

    private function authenticator(FirebaseTokenVerifier $verifier): FirebaseAuthenticator
    {
        return new FirebaseAuthenticator(
            $verifier,
            $this->users,
            $this->em,
            static::getContainer()->get(UrlGeneratorInterface::class),
        );
    }

    private function verifierReturning(VerifiedFirebaseToken $token): FirebaseTokenVerifier
    {
        return new class($token) implements FirebaseTokenVerifier {
            public function __construct(private readonly VerifiedFirebaseToken $token)
            {
            }

            public function verify(string $idToken): VerifiedFirebaseToken
            {
                return $this->token;
            }
        };
    }

    private function verifierRejecting(): FirebaseTokenVerifier
    {
        return new class implements FirebaseTokenVerifier {
            public function verify(string $idToken): VerifiedFirebaseToken
            {
                throw new InvalidFirebaseTokenException('signature invalide');
            }
        };
    }

    private function request(array $payload = ['idToken' => 'peu-importe']): Request
    {
        return new Request([], [], [], [], [], [], json_encode($payload));
    }

    private function resolve(FirebaseTokenVerifier $verifier, ?Request $request = null): User
    {
        $passport = $this->authenticator($verifier)->authenticate($request ?? $this->request());

        return $passport->getUser();
    }

    private function createUser(string $email, ?string $firebaseUid = null): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('hash-existant');
        $user->setDisplayName('pseudo_'.substr(md5($email), 0, 6));
        $user->setFirebaseUid($firebaseUid);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testRejectedTokenFailsAndCreatesNoUser(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        try {
            $this->resolve($this->verifierRejecting());
        } finally {
            $this->assertSame(0, $this->users->count([]));
        }
    }

    public function testMissingTokenInPayloadIsRejected(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $verifier = $this->verifierReturning(new VerifiedFirebaseToken('uid', 'a@b.com', true));
        $this->resolve($verifier, $this->request(['autre' => 'chose']));
    }

    public function testUnverifiedEmailIsRejected(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $verifier = $this->verifierReturning(
            new VerifiedFirebaseToken('uid-123', 'pirate@test.com', emailVerified: false)
        );

        try {
            $this->resolve($verifier);
        } finally {
            $this->assertSame(0, $this->users->count([]));
        }
    }

    public function testTokenWithoutEmailIsRejected(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $verifier = $this->verifierReturning(new VerifiedFirebaseToken('uid-123', null, true));
        $this->resolve($verifier);
    }

    public function testKnownFirebaseUidReturnsExistingUserWithoutCreating(): void
    {
        $existing = $this->createUser('bob@test.com', 'uid-bob');

        $verifier = $this->verifierReturning(new VerifiedFirebaseToken('uid-bob', 'bob@test.com', true));
        $resolved = $this->resolve($verifier);

        $this->assertSame($existing->getId(), $resolved->getId());
        $this->assertSame(1, $this->users->count([]));
    }

    public function testKnownEmailWithoutUidIsLinked(): void
    {
        $existing = $this->createUser('legacy@test.com');
        $this->assertNull($existing->getFirebaseUid());

        $verifier = $this->verifierReturning(new VerifiedFirebaseToken('uid-legacy', 'legacy@test.com', true));
        $resolved = $this->resolve($verifier);

        $this->assertSame($existing->getId(), $resolved->getId());
        $this->assertSame('uid-legacy', $resolved->getFirebaseUid());
        $this->assertSame(1, $this->users->count([]));
    }

    public function testUnknownAccountIsCreatedWithTraderRoleAndNoPassword(): void
    {
        $verifier = $this->verifierReturning(new VerifiedFirebaseToken('uid-new', 'new@test.com', true));
        $resolved = $this->resolve($verifier);

        $this->assertSame('new@test.com', $resolved->getEmail());
        $this->assertSame('uid-new', $resolved->getFirebaseUid());
        $this->assertContains(User::ROLE_TRADER, $resolved->getRoles());
        $this->assertNull($resolved->getPassword());
        // Le pseudo est collecté par l'onboarding, jamais déduit du compte Google.
        $this->assertNull($resolved->getDisplayName());
    }

    /**
     * L'UID est la clé d'identité : si l'adresse Google change, on continue de
     * reconnaître le compte et on ne touche pas à l'e-mail enregistré.
     */
    public function testEmailIsNeverResynchronised(): void
    {
        $existing = $this->createUser('ancien@test.com', 'uid-stable');

        $verifier = $this->verifierReturning(new VerifiedFirebaseToken('uid-stable', 'nouveau@test.com', true));
        $resolved = $this->resolve($verifier);

        $this->assertSame($existing->getId(), $resolved->getId());
        $this->assertSame('ancien@test.com', $resolved->getEmail());
    }
}
