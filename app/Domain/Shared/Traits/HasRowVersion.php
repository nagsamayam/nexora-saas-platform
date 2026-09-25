<?php

declare(strict_types=1);

namespace App\Domain\Shared\Traits;

use App\Domain\Shared\Exceptions\ConcurrencyException;

trait HasRowVersion
{
    /**
     * Update model attributes matching expected row version for optimistic concurrency control.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ConcurrencyException
     */
    public function updateWithOcc(array $attributes, ?int $expectedVersion = null): bool
    {
        $versionColumn = $this->getRowVersionColumn();
        $currentVersion = $expectedVersion ?? (int) $this->getAttribute($versionColumn);

        $attributesToUpdate = $attributes;
        $attributesToUpdate[$versionColumn] = $currentVersion + 1;

        $query = $this->newModelQuery()
            ->whereKey($this->getKey())
            ->where($versionColumn, $currentVersion);

        $affectedRows = $query->update($this->sanitizeAttributesForOcc($attributesToUpdate));

        if ($affectedRows === 0) {
            throw new ConcurrencyException;
        }

        $this->fill($attributesToUpdate);
        $this->syncOriginal();

        return true;
    }

    public function getRowVersionColumn(): string
    {
        return 'row_version';
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function sanitizeAttributesForOcc(array $attributes): array
    {
        $this->fill($attributes);

        return $this->getDirty();
    }
}
