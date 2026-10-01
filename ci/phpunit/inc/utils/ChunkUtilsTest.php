<?php

namespace Tests\Utils;

use Hashtopolis\dba\models\Assignment;
use Hashtopolis\dba\models\Task;
use Hashtopolis\inc\DataSet;
use Hashtopolis\inc\defines\DConfig;
use Hashtopolis\inc\defines\DPrince;
use Hashtopolis\inc\defines\DTaskStaticChunking;
use Hashtopolis\inc\HTException;
use Hashtopolis\inc\SConfig;
use Hashtopolis\inc\utils\ChunkUtils;
use PHPUnit\Framework\Attributes\DataProvider;
use Hashtopolis\TestBase;

require_once(dirname(__FILE__) . '/../../TestBase.php');
require_once(dirname(__FILE__) . '/../../../../src/inc/startup/include.php');

final class ChunkUtilsTest extends TestBase {

  // Injects a fake DataSet into the SConfig singleton via Reflection,
  // so tests can control config values without touching the database.
  private function mockSConfig(array $v): void {
    $p = (new \ReflectionClass(SConfig::class))->getProperty('instance');
    $p->setValue(null, new DataSet($v));
  }

  // Resets the SConfig singleton to null after every test so a mocked config
  // from one test never leaks into the next.
  protected function tearDown(): void {
    $p = (new \ReflectionClass(SConfig::class))->getProperty('instance');
    $p->setValue(null, null);
  }

  // Verifies that CHUNK_SIZE static mode bypasses all adaptive math and
  // returns the configured chunk size value directly.
  // Arg #2 (chunkSpeed) is an int now but is ignored entirely on the static path.
  public function testStaticChunkSizeReturnsValueDirectly(): void {
    $this->assertSame(25000, ChunkUtils::calculateChunkSize(1000000, 5000, 60, 1.0, DTaskStaticChunking::CHUNK_SIZE, 25000));
  }

  // Verifies that NUM_CHUNKS static mode divides the keyspace evenly and
  // rounds up (ceil) so no candidates are left out.
  // Result is cast to int because PHP ceil() returns float.
  public function testStaticNumChunksReturnsCeilDivision(): void {
    $this->assertSame((int) ceil(1000000 / 3), (int) ChunkUtils::calculateChunkSize(1000000, 5000, 60, 1.0, DTaskStaticChunking::NUM_CHUNKS, 3));
  }

  // Verifies that misconfigured static chunking inputs always throw HTException.
  // Four cases via data provider: CHUNK_SIZE=0, NUM_CHUNKS=0,
  // NUM_CHUNKS>10000 (flood protection), and an unknown mode constant.
  // PHPUnit 12 requires the #[DataProvider] attribute — @dataProvider docblock no longer works.
  #[DataProvider('staticExceptionCases')]
  public function testStaticChunkingInvalidInputThrowsHTException(int $mode, int $size): void {
    $this->expectException(HTException::class);
    ChunkUtils::calculateChunkSize(1000000, 5000, 60, 1.0, $mode, $size);
  }

  public static function staticExceptionCases(): array {
    return [
      'CHUNK_SIZE zero'      => [DTaskStaticChunking::CHUNK_SIZE, 0],
      'NUM_CHUNKS zero'      => [DTaskStaticChunking::NUM_CHUNKS, 0],
      'NUM_CHUNKS too large' => [DTaskStaticChunking::NUM_CHUNKS, 10001],
      'unknown mode'         => [99, 0],
    ];
  }

  // Verifies the bootstrap special case: a zero chunkSpeed means there is no
  // usable observed speed yet, so the entire keyspace is returned as one chunk.
  // This subsumes the legacy "benchmark == 0 => return keyspace" behaviour.
  public function testZeroChunkSpeedReturnsFullKeyspace(): void {
    $this->assertSame(500, ChunkUtils::calculateChunkSize(500, 0, 60));
  }

  // Verifies the adaptive sizing formula: size = floor(chunkSpeed * chunkTime).
  // The keyspace (999999999 here) does not enter the formula; it only bounds
  // dispatch elsewhere. Result is cast to int because PHP floor() returns float.
  public function testAdaptiveFormulaSizesFromChunkSpeed(): void {
    $this->assertSame((int) floor(5000 * 60), (int) ChunkUtils::calculateChunkSize(999999999, 5000, 60));
  }

  // Verifies the smallest positive product still yields a usable chunk of 1.
  // floor(1 * 1) * 1.0 == 1, so this goes through the normal adaptive path.
  // NOTE: the old fractional clamp/log branch (chunkSize <= 0 -> 1 + log entry)
  // is now unreachable from a positive integer chunkSpeed — a positive int speed
  // times a positive chunkTime can never floor below 1 — and is kept purely as
  // defense. Because this test never reaches that branch it must not touch
  // $GLOBALS['QUERY'] / Util::createLogEntry.
  public function testMinimumPositiveChunkSpeedReturnsOne(): void {
    $this->assertSame(1, (int) ChunkUtils::calculateChunkSize(1000000, 1, 1));
  }

  // Verifies that the tolerance multiplier correctly scales the chunk size up.
  // Both sides are cast to int because float arithmetic (300000.0 * 1.1)
  // produces 330000.00000000006 due to IEEE 754 precision — int cast aligns them.
  public function testToleranceScalesChunkSizeUp(): void {
    $base = (int) ChunkUtils::calculateChunkSize(1000000, 5000, 60, 1.0);
    $this->assertSame((int) ($base * 1.1), (int) ChunkUtils::calculateChunkSize(1000000, 5000, 60, 1.1));
  }

  // Verifies that chunkTime=0 triggers the SConfig fallback: the server-wide
  // CHUNK_DURATION value is used instead of the per-task setting.
  // Result is cast to int because PHP floor() returns float.
  public function testZeroChunkTimeFallsBackToSConfigValue(): void {
    $this->mockSConfig([DConfig::CHUNK_DURATION => 120]);
    $this->assertSame((int) floor(5000 * 120), (int) ChunkUtils::calculateChunkSize(999999999, 5000, 0));
  }

  // Verifies the PRINCE guard: PRINCE_KEYSPACE is a negative sentinel (-1605).
  // With a zero chunkSpeed the bootstrap branch fires, and because the keyspace
  // is not > 0 it must clamp to 1 rather than returning the negative sentinel.
  public function testPrinceKeyspaceWithZeroChunkSpeedReturnsOne(): void {
    $this->assertSame(1, (int) ChunkUtils::calculateChunkSize(DPrince::PRINCE_KEYSPACE, 0, 60));
  }

  // --- reconcileSpeed: exponential-moving-average blending of observed speed ---

  // Unseeded ($old <= 0): adopt the observed value verbatim.
  public function testReconcileSpeedAdoptsObservedWhenUnseeded(): void {
    $this->assertSame(1000000, ChunkUtils::reconcileSpeed(0, 1000000));
  }

  // Stall filter: an observed speed <= RECONCILE_MIN_SPEED (1) is treated as a
  // measurement artifact and ignored, keeping the previous speed.
  public function testReconcileSpeedIgnoresStall(): void {
    $this->assertSame(100000, ChunkUtils::reconcileSpeed(100000, 0));
  }

  // Upward moves are clamped to RECONCILE_MAX_UP_RATIO (4x) before blending:
  // upper = floor(100000 * 4) = 400000; target = 400000;
  // blended = 0.6*100000 + 0.4*400000 = 220000.
  public function testReconcileSpeedClampsUpwardJump(): void {
    $this->assertSame(220000, ChunkUtils::reconcileSpeed(100000, 1000000));
  }

  // Downward moves are NOT clamped — blend straight to the observed value:
  // blended = 0.6*1000000 + 0.4*100000 = 640000.
  public function testReconcileSpeedLeavesDownwardUnclamped(): void {
    $this->assertSame(640000, ChunkUtils::reconcileSpeed(1000000, 100000));
  }

  // Upward convergence: starting well below a steady observed speed, the clamped
  // EMA should climb past 900000 within a few iterations. Verified sequence is
  // 220000,484000,690400,814240,888544,933126 — crosses at iteration 6.
  public function testReconcileSpeedConvergesUpward(): void {
    $speed = 100000;
    $observed = 1000000;
    $crossed = false;
    for ($i = 1; $i <= 8; $i++) {
      $speed = ChunkUtils::reconcileSpeed($speed, $observed);
      if ($speed > 900000) {
        $crossed = true;
        break;
      }
    }
    $this->assertTrue($crossed, 'upward EMA should cross 900000 within 8 iterations');
  }

  // Downward convergence: starting well above a steady observed speed, the
  // (unclamped) EMA should fall below 110000 within a bounded number of steps.
  // The first step lands at exactly 640000, proving the downward path is
  // UNCLAMPED — a symmetric 4x clamp would instead floor the first step at
  // floor(1000000/4)=250000 territory and produce a different value. The
  // sequence is strictly decreasing and crosses below 110000 at iteration 9
  // (DESIGN's "~7" estimate was wrong); a safe upper bound of 12 is asserted.
  public function testReconcileSpeedConvergesDownward(): void {
    $speed = 1000000;
    $observed = 100000;
    $prev = PHP_INT_MAX;
    $crossed = false;
    $crossIter = null;
    for ($i = 1; $i <= 12; $i++) {
      $speed = ChunkUtils::reconcileSpeed($speed, $observed);
      if ($i === 1) {
        $this->assertSame(640000, $speed, 'first downward step proves no downward clamp');
      }
      $this->assertLessThan($prev, $speed, 'downward EMA must be strictly decreasing');
      $prev = $speed;
      if (!$crossed && $speed < 110000) {
        $crossed = true;
        $crossIter = $i;
      }
    }
    $this->assertTrue($crossed, 'downward EMA should cross below 110000 within 12 iterations');
    $this->assertSame(9, $crossIter, 'downward crossing happens at iteration 9');
  }

  // --- observedChunkSpeed: base-word rate from the keyspace-progress delta (multiplier-agnostic) ---

  // Steady interval: (checkpoint_now - checkpoint_prev) / (t_now - t_prev) = 50000 / 10 = 5000 base-words/s.
  public function testObservedChunkSpeedComputesBaseWordRate(): void {
    $this->assertSame(5000, ChunkUtils::observedChunkSpeed(55000, 5000, 1000, 1010));
  }

  // The rate is floored to an integer (intval): 10001 / 3 = 3333.67 -> 3333.
  public function testObservedChunkSpeedFloorsToInteger(): void {
    $this->assertSame(3333, ChunkUtils::observedChunkSpeed(10001, 0, 1000, 1003));
  }

  // A freshly (re)dispatched chunk has solveTime 0; that first interval is autotune-contaminated and
  // must be skipped (null) so the EWMA seed is not dragged down by the dispatch->first-report overhead.
  public function testObservedChunkSpeedSkipsFreshlyDispatchedChunk(): void {
    $this->assertNull(ChunkUtils::observedChunkSpeed(50000, 0, 0, 1000));
  }

  // Two reports in the same wall-clock second (dT = 0 < RECONCILE_MIN_INTERVAL) are not measurable.
  public function testObservedChunkSpeedSkipsSubSecondInterval(): void {
    $this->assertNull(ChunkUtils::observedChunkSpeed(50000, 0, 1000, 1000));
  }

  // Defensive: a non-monotonic clock (now < prev) yields a negative dT and is skipped, never a bogus rate.
  public function testObservedChunkSpeedSkipsNonPositiveInterval(): void {
    $this->assertNull(ChunkUtils::observedChunkSpeed(50000, 0, 1000, 999));
  }

  // No forward keyspace progress (dKeyspace = 0): a stalled report teaches us nothing -> skip.
  public function testObservedChunkSpeedSkipsNoForwardProgress(): void {
    $this->assertNull(ChunkUtils::observedChunkSpeed(5000, 5000, 1000, 1010));
  }

  // Backward progress (checkpoint reset / re-trim, dKeyspace < 0) must never produce a negative rate.
  public function testObservedChunkSpeedSkipsBackwardProgress(): void {
    $this->assertNull(ChunkUtils::observedChunkSpeed(4000, 5000, 1000, 1010));
  }

  // The signal is base-words/s and is multiplier-AGNOSTIC: it is a pure function of keyspace progress and
  // elapsed time, with no dependence on the raw hashcat hash-rate, the rule count, or the salt count
  // (none of which are even parameters). A 1-rule and a 16-rule attack advancing the same base-word
  // keyspace over the same wall-time therefore yield the SAME chunkSpeed -- which is exactly the bug fix.
  public function testObservedChunkSpeedIsMultiplierAgnostic(): void {
    $rate = ChunkUtils::observedChunkSpeed(15000, 0, 1000, 1003);   // 15000 base words over 3s
    $this->assertSame(5000, $rate);
    // Same base-word advance over the same elapsed time, different absolute window -> identical rate.
    $this->assertSame($rate, ChunkUtils::observedChunkSpeed(150000, 135000, 9000, 9003));
  }

  // --- benchmarkToChunkSpeed: normalise stored benchmark values to H/s ---

  // SPEED_TEST "speed:time" format: floor(speed * 1000 / time). Keyspace IGNORED.
  public function testBenchmarkToChunkSpeedSpeedTestFormat(): void {
    $this->assertSame(5000, ChunkUtils::benchmarkToChunkSpeed("5000:1000", null));
  }

  // SPEED_TEST format ignores the keyspace argument entirely:
  // floor(12000 * 1000 / 500) = 24000 regardless of the (here bogus) keyspace 999.
  public function testBenchmarkToChunkSpeedSpeedTestIgnoresKeyspace(): void {
    $this->assertSame(24000, ChunkUtils::benchmarkToChunkSpeed("12000:500", 999));
  }

  // RUN_TIME scalar format: floor(benchmark * keyspace / 100) = floor(50 * 1000000 / 100) = 500000.
  public function testBenchmarkToChunkSpeedRunTimeFormat(): void {
    $this->assertSame(500000, ChunkUtils::benchmarkToChunkSpeed(50, 1000000));
  }

  // A scalar RUN_TIME benchmark with no usable keyspace cannot be converted.
  #[DataProvider('benchmarkRunTimeNullKeyspaceCases')]
  public function testBenchmarkToChunkSpeedRunTimeNeedsKeyspace($keyspace): void {
    $this->assertNull(ChunkUtils::benchmarkToChunkSpeed(50, $keyspace));
  }

  public static function benchmarkRunTimeNullKeyspaceCases(): array {
    return [
      'null keyspace' => [null],
      'zero keyspace' => [0],
    ];
  }

  // Unparseable / non-positive benchmark inputs all normalise to null.
  #[DataProvider('benchmarkInvalidCases')]
  public function testBenchmarkToChunkSpeedReturnsNullOnInvalid($benchmark): void {
    $this->assertNull(ChunkUtils::benchmarkToChunkSpeed($benchmark, 1));
  }

  public static function benchmarkInvalidCases(): array {
    return [
      'zero speed component' => ["0:1000"],
      'zero time component'  => ["5000:0"],
      'non-numeric string'   => ["abc"],
      'empty string'         => [""],
    ];
  }

  // DESIGN §4.1 legacy-equivalence: seeding chunkSpeed from a RUN_TIME benchmark
  // and then applying the adaptive formula (size = floor(seed * chunkTime))
  // reproduces the old legacy size floor(keyspace * benchmark * chunkTime / 100).
  // seed = floor(50 * 1000000 / 100) = 500000; floor(500000 * 60) = 30000000
  //   == floor(1000000 * 50 * 60 / 100) = 30000000.
  public function testRunTimeSeedReproducesLegacySize(): void {
    $seed = ChunkUtils::benchmarkToChunkSpeed(50, 1000000);
    $this->assertSame((int) floor(1000000 * 50 * 60 / 100), (int) floor($seed * 60));
  }

  // DESIGN §4.1 nuance (review finding): the exact legacy equality above only holds when
  // benchmark*keyspace/100 is integral. When it is NOT, the intermediate floor in the seed makes
  // the adaptive first-chunk size diverge from the legacy size — but ALWAYS by strictly less than
  // one chunkTime worth of keyspace, i.e. a single short bootstrap chunk that the EWMA reconciler
  // erases on the first observed-speed report. Here legacy = floor(999999*50*60/100) = 29999970;
  // seed = floor(50*999999/100) = 499999; adaptive = floor(499999*60) = 29999940; divergence = 30.
  public function testRunTimeSeedDivergesFromLegacyByLessThanChunkTime(): void {
    $keyspace = 999999;
    $benchmark = 50;
    $chunkTime = 60;
    $legacy = (int) floor($keyspace * $benchmark * $chunkTime / 100);
    $seed = ChunkUtils::benchmarkToChunkSpeed($benchmark, $keyspace);
    $adaptive = (int) floor($seed * $chunkTime);
    $divergence = abs($legacy - $adaptive);
    $this->assertGreaterThan(0, $divergence, 'this case is intentionally non-integer-landing so divergence is nonzero');
    $this->assertLessThan($chunkTime, $divergence, 'seed-vs-legacy divergence must stay under one chunkTime');
  }

  // Verifies that createNewChunk() returns null when the full keyspace has been
  // consumed (keyspace == keyspaceProgress). A mocked Task is used so no DB
  // records are needed; the mock returns getKeyspace()=1000 and
  // getKeyspaceProgress()=1000, making remaining=0 and triggering the null path.
  // The Assignment mock returns getChunkSpeed()=0 so the (short-circuited)
  // calculateChunkSize call stays on the bootstrap path.
  public function testCreateNewChunkReturnsNullWhenKeyspaceExhausted(): void {
    $this->mockSConfig([DConfig::DISP_TOLERANCE => 0, DConfig::CHUNK_DURATION => 600]);
    $task = $this->createStub(Task::class);
    $task->method('getSkipKeyspace')->willReturn(0);
    $task->method('getKeyspaceProgress')->willReturn(1000);
    $task->method('getKeyspace')->willReturn(1000);
    $assignment = $this->createStub(Assignment::class);
    $assignment->method('getChunkSpeed')->willReturn(0);
    $this->assertNull(ChunkUtils::createNewChunk($task, $assignment));
  }

  // TODO: handleExistingChunk() and createNewChunk() require further test coverage.
}
