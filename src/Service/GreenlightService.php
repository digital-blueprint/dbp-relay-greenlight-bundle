<?php

declare(strict_types=1);

namespace Dbp\Relay\GreenlightBundle\Service;

use DBP\API\BaseBundle\API\PersonProviderInterface;
use DBP\API\BaseBundle\Entity\Person;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\GreenlightBundle\API\PersonPhotoProviderInterface;
use Dbp\Relay\GreenlightBundle\Entity\Permit;
use Dbp\Relay\GreenlightBundle\Entity\PermitPersistence;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

class GreenlightService
{
    /**
     * @var PersonProviderInterface
     */
    private $personProvider;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var PersonPhotoProviderInterface
     */
    private $personPhotoProviderInterface;

    public function __construct(PersonProviderInterface $personProvider, ManagerRegistry $managerRegistry, PersonPhotoProviderInterface $personPhotoProviderInterface)
    {
        $this->personProvider = $personProvider;
        $this->personPhotoProviderInterface = $personPhotoProviderInterface;
        $this->em = $managerRegistry->getManager('dbp_relay_greenlight_bundle');
    }

    private function getCurrentPerson(): Person
    {
        $person = $this->personProvider->getCurrentPerson();

        if (!$person) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, "Current person wasn't found!", 'greenlight:current-person-not-found');
        }

        return $person;
    }

    /**
     * Fetches a Permit.
     */
    public function getPermitById(string $identifier): ?Permit
    {
        /** @var PermitPersistence $permitPersistence */
        $permitPersistence = $this->em
            ->getRepository(PermitPersistence::class)
            ->find($identifier);

        if (!$permitPersistence) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'Permit was not found!', 'greenlight:permit-not-found');
        }

        $permit = Permit::fromPermitPersistence($permitPersistence);

        // try to get a photo of the person
        try {
            $person = $this->personProvider->getPerson($permit->getPersonId());
            $photo = $this->personPhotoProviderInterface->getPhotoData($person);
            $permit->setImage($photo);
        } catch (NotFoundHttpException $e) {
        }

        return $permit;
    }

    /**
     * Fetches all Permit entities for the current person.
     *
     * @return Permit[]
     */
    public function getPermitsForCurrentPerson(): array
    {
        $person = $this->getCurrentPerson();

        $permitPersistences = $this->em
            ->getRepository(PermitPersistence::class)
            ->findBy(['personId' => $person->getIdentifier()]);

        return Permit::fromPermitPersistences($permitPersistences);
    }

    /**
     * Fetches all expired Permit entities.
     *
     * @return Permit[]
     */
    public function getExpiredPermits(): array
    {
        $expr = Criteria::expr();
        $criteria = Criteria::create();
        $criteria->where($expr->lt('validUntil', new \DateTime('now')));

        $result = $this->em
            ->getRepository(PermitPersistence::class)
            ->matching($criteria);

        return Permit::fromPermitPersistences($result->getValues());
    }

    /**
     * Fetches a Permit for the current person.
     */
    public function getPermitByIdForCurrentPerson(string $identifier): ?Permit
    {
        $permit = $this->getPermitById($identifier);
        $person = $this->getCurrentPerson();

        if ($person->getIdentifier() !== $permit->getPersonId()) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, "Current person doesn't own this permit!", 'greenlight:person-does-not-own-permit');
        }

        return $permit;
    }

    /**
     * Removes a Permit for the current person.
     */
    public function removePermitByIdForCurrentPerson(string $identifier): void
    {
        $permit = $this->getPermitByIdForCurrentPerson($identifier);

        if ($permit) {
            $this->removePermit($permit);
        }
    }

    /**
     * Removes a Permit.
     */
    public function removePermit(Permit $permit): void
    {
        // Prevent "Detached entity cannot be removed" error by fetching the PermitPersistence
        // instead of using "PermitPersistence::fromPermit($permit)".
        // "$this->em->merge" would fix it too, but is deprecated
        /** @var PermitPersistence $permitPersistence */
        $permitPersistence = $this->em
            ->getRepository(PermitPersistence::class)
            ->find($permit->getIdentifier());

        $this->em->remove($permitPersistence);
        $this->em->flush();
    }

    public function createPermitForCurrentPerson(Permit $permit): Permit
    {
        $permitPersistence = PermitPersistence::fromPermit($permit);

        $permitPersistence->setIdentifier((string) Uuid::v4());
        $permitPersistence->setPersonId($this->personProvider->getCurrentPerson()->getIdentifier());
        $permitPersistence->setValidFrom(new \DateTime('now'));
        $permitPersistence->setValidUntil((new \DateTime('now'))->add(new \DateInterval('PT12H')));
        $permitPersistence->setImage('');

        $this->em->persist($permitPersistence);
        $this->em->flush();

        return Permit::fromPermitPersistence($permitPersistence);
    }
}
