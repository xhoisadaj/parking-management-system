<?php

namespace App\Services\Statistics;

/**
 * Walks a sorted list of [timestamp, delta] events. Sample times must be requested in ascending order.
 */
final class OccupancyTimeline
{
    private int $index = 0;

    private float $occupied = 0.0;

    /** @param  list<array{0: int, 1: float}>  $events */
    public function __construct(private readonly array $events) {}

    public function at(int $timestamp): float
    {
        while ($this->index < count($this->events) && $this->events[$this->index][0] <= $timestamp) {
            $this->occupied += $this->events[$this->index][1];
            $this->index++;
        }

        return round(max(0.0, $this->occupied), 2);
    }
}
