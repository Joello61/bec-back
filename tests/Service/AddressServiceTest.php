<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Address;
use App\Entity\Country;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Repository\AddressRepository;
use App\Repository\CountryRepository;
use App\Service\AddressService;
use App\Service\CurrencyService;
use App\Service\GeoDataService;
use App\Service\RealtimeNotifier;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Phase 4b, Lot 6 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : la regle des
 * 6 mois (Address::canBeModified(), entite reelle non mockee), les deux formats
 * Afrique/Diaspora, la detection automatique devise/langue/timezone, et le test de
 * regression du bug corrige au commit precedent (changement de pays sur updateAddress()).
 */
class AddressServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private AddressRepository&\PHPUnit\Framework\MockObject\MockObject $addressRepository;
    private CurrencyService&\PHPUnit\Framework\MockObject\MockObject $currencyService;
    private GeoDataService&\PHPUnit\Framework\MockObject\MockObject $geoDataService;
    private CountryRepository&\PHPUnit\Framework\MockObject\MockObject $countryRepository;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;
    private AddressService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->addressRepository = $this->createMock(AddressRepository::class);
        $this->currencyService = $this->createMock(CurrencyService::class);
        $this->geoDataService = $this->createMock(GeoDataService::class);
        $this->countryRepository = $this->createMock(CountryRepository::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);
        $this->geoDataService->method('getTimeZoneByCityAndPays')->willReturn('Africa/Douala');
        $country = new Country();
        $this->setEntityId($country, 1);
        $this->countryRepository->method('findOneBy')->willReturn($country);

        $this->service = new AddressService(
            $this->em,
            $this->addressRepository,
            $this->currencyService,
            new NullLogger(),
            $this->geoDataService,
            $this->countryRepository,
            $this->notifier,
        );
    }

    private function userWithSettings(int $id): User
    {
        $user = new User();
        $user->setEmail('user-' . $id . '@example.test');
        $user->setNom('Nom');
        $user->setPassword('irrelevant');
        $this->setEntityId($user, $id);
        $settings = new UserSettings();
        $settings->setUser($user);
        $user->setSettings($settings);

        return $user;
    }

    private function africanData(string $pays = 'Cameroun', string $ville = 'Douala'): array
    {
        return ['pays' => $pays, 'ville' => $ville, 'quartier' => 'Bonapriso'];
    }

    private function diasporaData(): array
    {
        return ['pays' => 'France', 'ville' => 'Paris', 'adresseLigne1' => '12 rue de la Paix', 'codePostal' => '75002'];
    }

    // ==================== createAddress ====================

    public function testCreateAddressReturnsTheExistingOneWithoutCreatingANewOne(): void
    {
        $user = $this->userWithSettings(1);
        $existing = new Address();
        $this->addressRepository->method('findByUser')->willReturn($existing);
        $this->em->expects(self::never())->method('persist');

        self::assertSame($existing, $this->service->createAddress($user, $this->africanData()));
    }

    public function testCreateAddressRejectsMissingPaysOrVille(): void
    {
        $this->addressRepository->method('findByUser')->willReturn(null);

        $this->expectException(BadRequestHttpException::class);
        $this->service->createAddress($this->userWithSettings(1), ['pays' => '', 'ville' => 'Douala', 'quartier' => 'X']);
    }

    public function testCreateAddressRejectsWhenNeitherFormatIsProvided(): void
    {
        $this->addressRepository->method('findByUser')->willReturn(null);

        $this->expectException(BadRequestHttpException::class);
        $this->service->createAddress($this->userWithSettings(1), ['pays' => 'Cameroun', 'ville' => 'Douala']);
    }

    public function testCreateAddressSucceedsWithTheAfricanFormat(): void
    {
        $user = $this->userWithSettings(1);
        $this->addressRepository->method('findByUser')->willReturn(null);
        $this->currencyService->method('getCurrencyAndLangByCountry')->willReturn(['currency' => 'XAF', 'languages' => 'fr-FR']);
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(Address::class));

        $address = $this->service->createAddress($user, $this->africanData());

        self::assertSame('Bonapriso', $address->getQuartier());
        self::assertSame('XAF', $user->getSettings()->getDevise());
        self::assertSame('Africa/Douala', $user->getSettings()->getTimezone());
    }

    public function testCreateAddressSucceedsWithTheDiasporaFormat(): void
    {
        $user = $this->userWithSettings(1);
        $this->addressRepository->method('findByUser')->willReturn(null);
        $this->currencyService->method('getCurrencyAndLangByCountry')->willReturn(['currency' => 'EUR', 'languages' => 'fr-FR']);

        $address = $this->service->createAddress($user, $this->diasporaData());

        self::assertSame('12 rue de la Paix', $address->getAdresseLigne1());
        self::assertSame('75002', $address->getCodePostal());
    }

    public function testCreateAddressSucceedsWithoutATimezoneWhenTheCountryIsNotFound(): void
    {
        // Regression : countryRepository->findOneBy() peut renvoyer null (pays non
        // seede/orthographe non reconnue) - createAddress() ne doit pas planter en
        // appelant getId() sur ce null, la detection de fuseau horaire doit simplement
        // etre sautee (CurrencyService gere deja ce cas cote devise, avec repli sur EUR).
        // Mock/service locaux (plutot que $this->service) : setUp() stub deja
        // countryRepository->findOneBy() pour renvoyer un Country valide.
        $user = $this->userWithSettings(1);
        $addressRepository = $this->createMock(AddressRepository::class);
        $addressRepository->method('findByUser')->willReturn(null);
        $currencyService = $this->createMock(CurrencyService::class);
        $currencyService->method('getCurrencyAndLangByCountry')->willReturn(['currency' => 'EUR', 'languages' => 'fr-FR']);
        $geoDataService = $this->createMock(GeoDataService::class);
        $geoDataService->expects(self::never())->method('getTimeZoneByCityAndPays');
        $countryRepository = $this->createMock(CountryRepository::class);
        $countryRepository->method('findOneBy')->willReturn(null);

        $service = new AddressService(
            $this->em,
            $addressRepository,
            $currencyService,
            new NullLogger(),
            $geoDataService,
            $countryRepository,
            $this->notifier,
        );

        $address = $service->createAddress($user, $this->africanData(pays: 'Paysimaginaire'));

        self::assertSame('Bonapriso', $address->getQuartier());
        // setTimezone() n'est jamais appele : le fuseau horaire par defaut de UserSettings reste inchange.
        self::assertSame('Africa/Douala', $user->getSettings()->getTimezone());
    }

    // ==================== updateAddress ====================

    public function testUpdateAddressRejectsWhenTheSixMonthConstraintApplies(): void
    {
        $user = $this->userWithSettings(1);
        $address = new Address();
        $address->setUser($user);
        $address->setPays('Cameroun');
        $address->setVille('Douala');
        $address->setQuartier('Bonapriso');
        $address->markAsModified();

        $this->expectException(BadRequestHttpException::class);
        $this->service->updateAddress($address, $this->africanData('Cameroun', 'Yaounde'));
    }

    public function testUpdateAddressRejectsInvalidData(): void
    {
        $address = new Address();
        $address->setUser($this->userWithSettings(1));
        $address->setPays('Cameroun');
        $address->setVille('Douala');
        $address->setQuartier('Bonapriso');

        $this->expectException(BadRequestHttpException::class);
        $this->service->updateAddress($address, ['pays' => 'Cameroun', 'ville' => 'Douala']);
    }

    public function testUpdateAddressWithoutCountryChangeLeavesCurrencyUntouched(): void
    {
        $user = $this->userWithSettings(1);
        $user->getSettings()->setDevise('EUR');
        $address = new Address();
        $address->setUser($user);
        $address->setPays('Cameroun');
        $address->setVille('Douala');
        $address->setQuartier('Bonapriso');
        $this->currencyService->expects(self::never())->method('getCurrencyAndLangByCountry');

        $result = $this->service->updateAddress($address, $this->africanData('Cameroun', 'Yaounde'));

        self::assertSame('Yaounde', $result->getVille());
        self::assertSame('EUR', $user->getSettings()->getDevise());
    }

    public function testUpdateAddressWithACountryChangeUpdatesCurrencyAndLanguageWithoutCrashing(): void
    {
        // Regression test du bug corrige au commit precedent : getCurrencyAndLangByCountry()
        // renvoie la cle 'languages', pas 'lang' - avant le fix, ce test plantait avec un
        // TypeError (UserSettings::setLangue() n'accepte pas null).
        $user = $this->userWithSettings(1);
        $address = new Address();
        $address->setUser($user);
        $address->setPays('Cameroun');
        $address->setVille('Douala');
        $address->setQuartier('Bonapriso');
        $this->currencyService->method('getCurrencyAndLangByCountry')->willReturn(['currency' => 'EUR', 'languages' => 'fr-FR']);

        $this->service->updateAddress($address, $this->diasporaData());

        self::assertSame('EUR', $user->getSettings()->getDevise());
        self::assertSame('fr-FR', $user->getSettings()->getLangue());
    }

    public function testUpdateAddressResetsFormatFieldsWhenSwitchingFromAfricanToDiaspora(): void
    {
        $user = $this->userWithSettings(1);
        $address = new Address();
        $address->setUser($user);
        $address->setPays('France');
        $address->setVille('Paris');
        $address->setQuartier('Ancien quartier');

        $result = $this->service->updateAddress($address, $this->diasporaData());

        self::assertNull($result->getQuartier(), 'le passage au format diaspora doit effacer le champ quartier du format africain');
    }

    // ==================== canUserModifyAddress / getModificationInfo ====================

    public function testCanUserModifyAddressIsTrueWithoutAnExistingAddress(): void
    {
        $this->addressRepository->method('findByUser')->willReturn(null);

        self::assertTrue($this->service->canUserModifyAddress($this->userWithSettings(1)));
    }

    public function testCanUserModifyAddressIsFalseWithinTheSixMonthWindow(): void
    {
        $address = new Address();
        $address->markAsModified();
        $this->addressRepository->method('findByUser')->willReturn($address);

        self::assertFalse($this->service->canUserModifyAddress($this->userWithSettings(1)));
    }

    public function testGetModificationInfoWithoutAnAddress(): void
    {
        $this->addressRepository->method('findByUser')->willReturn(null);

        $info = $this->service->getModificationInfo($this->userWithSettings(1));

        self::assertTrue($info['canModify']);
        self::assertFalse($info['hasAddress']);
    }

    public function testGetModificationInfoWhenBlockedByTheSixMonthWindow(): void
    {
        $address = new Address();
        $address->markAsModified();
        $this->addressRepository->method('findByUser')->willReturn($address);

        $info = $this->service->getModificationInfo($this->userWithSettings(1));

        self::assertFalse($info['canModify']);
        self::assertArrayHasKey('nextModificationDate', $info);
        self::assertArrayHasKey('daysRemaining', $info);
    }

    // ==================== deleteAddress ====================

    public function testDeleteAddressRemovesIt(): void
    {
        $address = new Address();
        $address->setUser($this->userWithSettings(1));
        $this->em->expects(self::once())->method('remove')->with($address);
        $this->em->expects(self::once())->method('flush');

        $this->service->deleteAddress($address);
    }
}
