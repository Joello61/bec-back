<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Demande;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Entity\Voyage;
use App\Service\VisibilityService;
use App\Tests\Support\InMemoryUserTrait;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * VisibilityService centralise le filtrage par preferences de confidentialite
 * (UserSettings) - tests unitaires purs, aucune dependance externe au service.
 */
class VisibilityServiceTest extends TestCase
{
    use InMemoryUserTrait;

    private VisibilityService $service;

    protected function setUp(): void
    {
        $this->service = new VisibilityService();
    }

    private function settingsFor(User $user): UserSettings
    {
        $settings = new UserSettings();
        $settings->setUser($user);
        $user->setSettings($settings);

        return $settings;
    }

    private function voyage(User $voyageur): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);

        return $voyage;
    }

    private function demande(User $client): Demande
    {
        $demande = new Demande();
        $demande->setClient($client);

        return $demande;
    }

    public function testVoyageVisibleToOwnerRegardlessOfSettings(): void
    {
        $owner = $this->makeUser();
        $this->settingsFor($owner)->setShowInSearchResults(false)->setProfileVisibility('private');

        self::assertTrue($this->service->isVoyageVisibleFor($this->voyage($owner), $owner));
    }

    public function testVoyageHiddenFromThirdPartyWhenOwnerOptsOutOfSearch(): void
    {
        $owner = $this->makeUser();
        $this->settingsFor($owner)->setShowInSearchResults(false);
        $thirdParty = $this->makeUser();

        self::assertFalse($this->service->isVoyageVisibleFor($this->voyage($owner), $thirdParty));
    }

    public function testVoyageVisibleByDefaultWhenOwnerHasNoSettings(): void
    {
        $owner = $this->makeUser();
        $thirdParty = $this->makeUser();

        self::assertTrue($this->service->isVoyageVisibleFor($this->voyage($owner), $thirdParty));
    }

    public function testDemandeHiddenFromThirdPartyWhenClientOptsOutOfSearch(): void
    {
        $client = $this->makeUser();
        $this->settingsFor($client)->setShowInSearchResults(false);
        $thirdParty = $this->makeUser();

        self::assertFalse($this->service->isDemandeVisibleFor($this->demande($client), $thirdParty));
    }

    public function testDemandeVisibleToOwnerEvenWhenPrivate(): void
    {
        $client = $this->makeUser();
        $this->settingsFor($client)->setProfileVisibility('private');

        self::assertTrue($this->service->isDemandeVisibleFor($this->demande($client), $client));
    }

    public function testProfileVisibleToAnyoneWhenPublic(): void
    {
        $owner = $this->makeUser();
        $this->settingsFor($owner)->setProfileVisibility('public');

        self::assertTrue($this->service->isProfileVisibleFor($owner, null));
        self::assertTrue($this->service->isProfileVisibleFor($owner, $this->makeUser()));
    }

    public function testProfileHiddenFromAnonymousWhenPrivate(): void
    {
        $owner = $this->makeUser();
        $this->settingsFor($owner)->setProfileVisibility('private');

        self::assertFalse($this->service->isProfileVisibleFor($owner, null));
    }

    public function testProfileVerifiedOnlyGrantsVerifiedViewerOnly(): void
    {
        $owner = $this->makeUser();
        $this->settingsFor($owner)->setProfileVisibility('verified_only');
        $verifiedViewer = $this->makeUser([], profileComplete: true);
        $unverifiedViewer = $this->makeUser();

        self::assertTrue($this->service->isProfileVisibleFor($owner, $verifiedViewer));
        self::assertFalse($this->service->isProfileVisibleFor($owner, $unverifiedViewer));
    }

    public function testPhoneHiddenByDefaultWithoutSettings(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser();

        self::assertFalse($this->service->isPhoneVisibleFor($owner, $viewer), 'sans UserSettings, le telephone doit rester cache par defaut (a l\'inverse de la visibilite generale du profil)');
    }

    public function testEmailHiddenByDefaultWithoutSettings(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser();

        self::assertFalse($this->service->isEmailVisibleFor($owner, $viewer));
    }

    public function testPhoneAlwaysVisibleToOwnerThemself(): void
    {
        $owner = $this->makeUser();
        $this->settingsFor($owner)->setShowPhone(false);

        self::assertTrue($this->service->isPhoneVisibleFor($owner, $owner));
    }

    public function testInjectContactIfVisibleInjectsOnlyWhatIsAuthorized(): void
    {
        $owner = $this->makeUser();
        $this->settingsFor($owner)->setShowEmail(true)->setShowPhone(false);
        $owner->setTelephone('+237600000000');
        $viewer = $this->makeUser();

        $normalized = $this->service->injectContactIfVisible(['voyageur' => []], 'voyageur', $owner, $viewer);

        self::assertSame($owner->getEmail(), $normalized['voyageur']['email']);
        self::assertArrayNotHasKey('telephone', $normalized['voyageur']);
    }

    public function testFilterVisibleVoyagesRemovesHiddenOwnersButKeepsViewerOwn(): void
    {
        $viewer = $this->makeUser();
        $hiddenOwner = $this->makeUser();
        $this->settingsFor($hiddenOwner)->setShowInSearchResults(false);
        $openOwner = $this->makeUser();

        $viewerOwnVoyage = $this->voyage($viewer);
        $hiddenVoyage = $this->voyage($hiddenOwner);
        $openVoyage = $this->voyage($openOwner);

        $result = $this->service->filterVisibleVoyages([$viewerOwnVoyage, $hiddenVoyage, $openVoyage], $viewer);

        self::assertContains($viewerOwnVoyage, $result);
        self::assertContains($openVoyage, $result);
        self::assertNotContains($hiddenVoyage, $result);
    }

    public function testAreStatsVisibleForDefaultsToVisibleWithoutSettings(): void
    {
        $owner = $this->makeUser();

        self::assertTrue($this->service->areStatsVisibleFor($owner, $this->makeUser()));
    }

    public function testAreStatsVisibleForAlwaysTrueForOwner(): void
    {
        $owner = $this->makeUser();
        $this->settingsFor($owner)->setShowStats(false);

        self::assertTrue($this->service->areStatsVisibleFor($owner, $owner));
    }

    public function testCanSendMessageToDefaultsToAllowedWithoutSettings(): void
    {
        $sender = $this->makeUser();
        $recipient = $this->makeUser();

        self::assertTrue($this->service->canSendMessageTo($sender, $recipient));
    }
}
