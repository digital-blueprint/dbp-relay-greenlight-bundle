<?php

declare(strict_types=1);

namespace Dbp\Relay\GreenlightBundle\Service;

use Dbp\Relay\GreenlightBundle\VizHash\Utils;
use Dbp\Relay\GreenlightBundle\VizHash\VizHash;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class VizHashProvider
{
    /**
     * @var ParameterBagInterface
     */
    private $parameters;

    public function __construct(ParameterBagInterface $parameters)
    {
        $this->parameters = $parameters;
    }

    /**
     * Create a jpeg image with a centered photo.
     */
    public function createImageWithPhoto(string $input, string $photoData, int $size): string
    {
        $font = __DIR__.'/../Assets/sourcesanspro.ttf';

        return VizHash::create($input, $photoData, $size, null, $font, 80);
    }

    /**
     * Create a jpeg image with a centered image indicating a missing photo.
     */
    public function createImageMissingPhoto(string $input, int $size): string
    {
        $font = __DIR__.'/../Assets/sourcesanspro.ttf';
        $photoData = file_get_contents(__DIR__.'/../Assets/missing_photo.png');

        return VizHash::create($input, $photoData, $size, null, $font, 80);
    }

    /**
     * Create a jpeg image with an example photo and a watermark.
     */
    public function createReferenceImage(string $input, int $size): string
    {
        $font = __DIR__.'/../Assets/sourcesanspro.ttf';
        $photoData = file_get_contents(__DIR__.'/../Assets/example_photo.jpg');

        return VizHash::create($input, $photoData, $size, 'REFERENCE TICKET', $font, 80);
    }

    /**
     * This can be passed to createImage() as $input. The result changes ~ every hour.
     */
    public function getCurrentInput(): string
    {
        if (!$this->parameters->has('kernel.secret')) {
            throw new \RuntimeException('secret required');
        }
        // Returns a different string on every server.
        $serverInput = $this->parameters->get('kernel.secret');
        assert(is_string($serverInput));

        // This returns a different string 20 minutes after every hour.
        $currentTime = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $timeInput = Utils::getRollingInput20MinPastHour($currentTime);

        return hash('sha256', $timeInput.$serverInput);
    }
}
