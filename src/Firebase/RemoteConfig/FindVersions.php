<?php

declare(strict_types=1);

namespace Kreait\Firebase\RemoteConfig;

use DateTimeImmutable;
use Kreait\Firebase\Util\DT;

class FindVersions
{
    /**
     * @var DateTimeImmutable|null
     */
    private $since;

    /**
     * @var DateTimeImmutable|null
     */
    private $until;

    /**
     * @var VersionNumber|null
     */
    private $upToVersion;

    /**
     * @var int|null
     */
    private $limit;

    public static function fromArray(array $params): self
    {
        $new = new self();

        if ($value = $params['since'] ?? null) {
            $new->since = DT::toUTCDateTimeImmutable($value);
        }

        if ($value = $params['until'] ?? null) {
            $new->until = DT::toUTCDateTimeImmutable($value);
        }

        if ($value = $params['up_to_version'] ?? null) {
            $new->upToVersion = $value instanceof VersionNumber ? $value : VersionNumber::fromValue($value);
        }

        if ($value = $params['limit'] ?? null) {
            $new->limit = (int) $value;
        }

        return $new;
    }

    public static function all(): self
    {
        return new self();
    }

    public function since(): ?DateTimeImmutable
    {
        return $this->since;
    }

    public function until(): ?DateTimeImmutable
    {
        return $this->until;
    }

    public function upToVersion(): ?VersionNumber
    {
        return $this->upToVersion;
    }

    public function limit(): ?int
    {
        return $this->limit;
    }
}
