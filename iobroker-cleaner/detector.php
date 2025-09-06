<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

class PlausibilityDetector {
	private ?float $previousRawValue = null;
	private ?float $previousGoodValue = null;
	private ?int $previousGoodTimestamp = null;

	/** @var array<int,float> */
	private array $recentRawDeltas = [];
	/** @var array<int,array{ts:int,val:float}> */
	private array $goodHistory = [];

	public function reset(): void {
		$this->previousRawValue = null;
		$this->previousGoodValue = null;
		$this->previousGoodTimestamp = null;
		$this->recentRawDeltas = [];
		$this->goodHistory = [];
	}

	/**
	 * Analyze the next point and decide if it is an anomaly.
	 * Returns [isAnomaly, deltaPrevRaw, deltaPrevGood]
	 */
	public function analyze(int $timestampMs, float $value): array {
		$deltaPrevRaw = $this->previousRawValue === null ? 0.0 : ($value - $this->previousRawValue);
		$deltaPrevGoodBefore = $this->previousGoodValue === null ? 0.0 : ($value - $this->previousGoodValue);

		// Update recent raw deltas buffer (includes current raw delta)
		if ($this->previousRawValue !== null) {
			$this->recentRawDeltas[] = $deltaPrevRaw;
			if (count($this->recentRawDeltas) > SMALL_DELTA_CLUSTER_COUNT) {
				array_shift($this->recentRawDeltas);
			}
		}

		// Base anomaly detection vs last good value
		$candidateAnomaly = false;
		if ($this->previousGoodValue !== null) {
			$deltaFromGood = $value - $this->previousGoodValue;
			$candidateAnomaly = ($deltaFromGood < (-1.0 * DROP_THRESHOLD)) || ($deltaFromGood > SPIKE_THRESHOLD);
		}

		// Heuristic 1: small-delta cluster detection (stabilization after a jump)
		$isClusterStable = $this->isSmallDeltaCluster();

		// Heuristic 2: moving average rate plausibility against last good points
		$isPlausibleByRate = false;
		if ($this->previousGoodValue !== null && $this->previousGoodTimestamp !== null) {
			$dtMs = max(0, $timestampMs - $this->previousGoodTimestamp);
			if ($dtMs > 0) {
				$avgRate = $this->averageRatePerMs(); // units per ms
				if ($avgRate !== null) {
					$expected = $this->previousGoodValue + ($avgRate * $dtMs);
					$avgAbsRate = $this->averageAbsRatePerMs() ?? abs($avgRate);
					$tolerance = (RATE_TOLERANCE_MULTIPLIER * $avgAbsRate * $dtMs) + RATE_TOLERANCE_ABS;
					$isPlausibleByRate = abs($value - $expected) <= $tolerance;
				}
			}
		}

		$isAnomaly = $candidateAnomaly && !($isClusterStable || $isPlausibleByRate);

		// Advance good baseline if not an anomaly OR if we detect stabilization cluster
		if (!$isAnomaly || $isClusterStable) {
			$this->previousGoodValue = $value;
			$this->previousGoodTimestamp = $timestampMs;
			$this->appendGood($timestampMs, $value);
		}

		// Always advance previous raw value
		$this->previousRawValue = $value;

		// Report deltas relative to the previous good baseline (before potential update)
		return [$isAnomaly, $deltaPrevRaw, $deltaPrevGoodBefore];
	}

	private function isSmallDeltaCluster(): bool {
		if (count($this->recentRawDeltas) < SMALL_DELTA_CLUSTER_COUNT) {
			return false;
		}
		foreach ($this->recentRawDeltas as $d) {
			if (abs($d) > SMALL_DELTA_ABS_MAX) {
				return false;
			}
		}
		return true;
	}

	private function appendGood(int $ts, float $val): void {
		$this->goodHistory[] = ['ts' => $ts, 'val' => $val];
		if (count($this->goodHistory) > MOVING_WINDOW_SIZE_GOOD) {
			array_shift($this->goodHistory);
		}
	}

	private function averageRatePerMs(): ?float {
		$count = count($this->goodHistory);
		if ($count < 2) {
			return null;
		}
		$sum = 0.0;
		$num = 0;
		for ($i = 1; $i < $count; $i++) {
			$dt = $this->goodHistory[$i]['ts'] - $this->goodHistory[$i-1]['ts'];
			if ($dt <= 0) {
				continue;
			}
			$dv = $this->goodHistory[$i]['val'] - $this->goodHistory[$i-1]['val'];
			$sum += ($dv / $dt);
			$num++;
		}
		if ($num === 0) {
			return null;
		}
		return $sum / $num;
	}

	private function averageAbsRatePerMs(): ?float {
		$count = count($this->goodHistory);
		if ($count < 2) {
			return null;
		}
		$sum = 0.0;
		$num = 0;
		for ($i = 1; $i < $count; $i++) {
			$dt = $this->goodHistory[$i]['ts'] - $this->goodHistory[$i-1]['ts'];
			if ($dt <= 0) {
				continue;
			}
			$dv = $this->goodHistory[$i]['val'] - $this->goodHistory[$i-1]['val'];
			$sum += abs($dv / $dt);
			$num++;
		}
		if ($num === 0) {
			return null;
		}
		return $sum / $num;
	}
}

?>

