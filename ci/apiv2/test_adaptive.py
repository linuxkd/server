"""Adaptive (normal) chunk-sizing coverage through the live apiv2 + agent protocol.

Every other task fixture in this suite uses STATIC chunking (``staticChunks`` 1/2), so the adaptive
code path -- seed ``chunkSpeed`` from a benchmark, then reconcile it toward the agent's observed speed
on every ``sendProgress`` and re-size the next chunk -- had zero integration coverage.
``create_task_005.json`` is the only NORMAL-chunking fixture (``staticChunks: 0``,
``useNewBench: false``, ``chunkTime: 10``), which forces ``ChunkUtils::calculateChunkSize`` down the
adaptive branch (``size = floor(chunkSpeed * chunkTime)``).

The reconcile signal is the OBSERVED BASE-WORD RATE, derived server-side from the keyspace-progress
delta between two consecutive status reports (``(checkpoint_now - checkpoint_prev) / (t_now - t_prev)``)
-- NOT the raw hashcat hash-rate the agent reports. For an ``-a 0 -r`` / salted attack the raw rate is
``base-words/s * rule-count * salt-count`` while a chunk's ``length`` and a task's ``keyspace`` are in
base words, so feeding the raw rate into the sizer would over-size every chunk by the multiplier. See
``ChunkUtils::observedChunkSpeed`` and ``ChunkUtils::reconcileSpeed``.

Because the rate is over wall-clock time, these integration tests space the bracketing reports with a
real (~3.5s) sleep so the server-side ``solveTime`` delta is measurable; the first report after a
chunk is dispatched has ``solveTime == 0`` and is deliberately skipped (autotune-contaminated bootstrap).
The exact rate arithmetic is pinned deterministically (no clock) by the phpunit ``observedChunkSpeed``
unit tests; the assertions here are direction/bound based so a +-1s wall-clock wobble cannot flake them.
"""
import time

from hashtopolis import AgentAssignment, Chunk, Config, Task

from hashtopolis_agent import ProcessState
from utils import (
    BaseTest,
    do_create_agentassignent,
    do_create_dummy_agent,
    do_create_hashlist,
    do_create_task,
)

# --- experiment constants ---
KEYSPACE = 30_000_000
CHUNK_TIME = 10             # must match create_task_005.json
RUNTIME_BENCHMARK = 0.0005  # a deliberately small runtime-benchmark result -> a low seed (the undermeasure)
MEASURE_GAP_SECONDS = 3.5   # real gap between bracketing reports so the server-side solveTime delta is >=1s


class AdaptiveChunkSizingTest(BaseTest):
    model_class = Task

    def _setup_normal_task_agent(self, runtime_benchmark=RUNTIME_BENCHMARK):
        """Create a NORMAL-chunking task (fixture 005) with one dummy agent assigned and benchmarked.

        Drives the agent through the exact protocol the server gates on for a non-small, non-static task:
        getTask -> sendKeyspace -> sendBenchmark(run) -> getChunk (first real chunk). Returns the dummy
        agent, the live Task and the Agent objects, with the first chunk dispatched in ``dummy_agent.chunk``.
        """
        dummy_agent, agent = do_create_dummy_agent()
        hashlist = do_create_hashlist()
        task = do_create_task(hashlist=hashlist, file_id='005')
        do_create_agentassignent(agent, task)

        # Register for teardown. tearDown pops LIFO, and a Task must be deleted before its Hashlist (FK),
        # so push agent -> hashlist -> task to pop task -> hashlist -> agent.
        self.delete_after_test(agent)
        self.delete_after_test(hashlist)
        self.delete_after_test(task)

        dummy_agent.get_task()
        dummy_agent.get_hashlist()

        # keyspace measurement: getChunk returns keyspace_required, then we report it
        dummy_agent.get_chunk()
        dummy_agent.send_keyspace(keyspace=KEYSPACE)

        # benchmark: getChunk returns benchmark, then we report a deliberately LOW runtime result
        dummy_agent.get_chunk()
        dummy_agent.send_benchmark(benchmark_type="run", result=runtime_benchmark)

        # first real chunk dispatched (sized from the seeded chunkSpeed)
        dummy_agent.get_chunk()

        return dummy_agent, task, agent

    def _chunk_speed(self, task, agent):
        """Read the server-side canonical chunkSpeed for this (agent, task) assignment via apiv2."""
        assignments = list(AgentAssignment.objects.filter(taskId=task.id, agentId=agent.id))
        self.assertEqual(len(assignments), 1, "expected exactly one assignment for the agent/task")
        return assignments[0].chunkSpeed

    # ------------------------------------------------------------------ smoke

    def test_normal_chunking_task_seeds_and_dispatches(self):
        """Smoke: a NORMAL-chunking task creates, the runtime benchmark seeds a positive chunkSpeed, and
        the server dispatches a real chunk whose length follows the adaptive formula (chunkSpeed*chunkTime).
        """
        dummy_agent, task, agent = self._setup_normal_task_agent()

        live_task = Task.objects.get(taskId=task.id)
        self.assertEqual(live_task.staticChunks, 0, "fixture 005 must use NORMAL (non-static) chunking")
        self.assertEqual(live_task.useNewBench, 0, "fixture 005 must use the runtime benchmark path")
        self.assertEqual(live_task.chunkTime, CHUNK_TIME)

        seeded = self._chunk_speed(task, agent)
        self.assertIsNotNone(seeded, "chunkSpeed must be seeded (non-null) after a runtime benchmark")
        self.assertGreater(seeded, 0, "seeded chunkSpeed must be positive")

        self.assertIn('length', dummy_agent.chunk,
                      f"expected a dispatched chunk, got: {dummy_agent.chunk}")
        first_len = int(dummy_agent.chunk['length'])
        self.assertEqual(first_len, seeded * CHUNK_TIME,
                         "first chunk length must follow the adaptive formula floor(chunkSpeed*chunkTime)")

    # ----------------------------------------------------- unit-mismatch regression

    def test_chunk_speed_tracks_base_words_not_raw_hashrate(self):
        """REGRESSION: reconciled chunkSpeed must track the BASE-WORD rate (keyspace progress / wall
        time), NOT the raw hashcat hash-rate the agent reports.

        For an ``-a 0 -r`` attack hashcat's reported H/s == base-words/s x rule-count (x salt-count),
        but a chunk's ``length`` and a task's ``keyspace`` are measured in BASE WORDS. Feeding the raw
        H/s into the sizer inflates chunkSpeed by ~the rule count, oversizing every chunk for the whole
        assignment. Here we simulate a 16x rule multiplier: the agent reports ``speed = BASE_RATE*16``
        while its keyspace progress only advances at ~BASE_RATE per wall-second. The canonical chunkSpeed
        must converge toward BASE_RATE, an order of magnitude below the inflated raw rate.

        On the buggy code (reconcile fed the raw ``speed``) chunkSpeed climbs toward BASE_RATE*16 and
        blows past every bound below; correct code holds it near BASE_RATE.
        """
        RULE_COUNT = 16                               # simulated -r rule multiplier

        # The runtime benchmark seeds a positive chunkSpeed (the BASE_RATE we will hold the keyspace
        # progress to). We derive BASE_RATE from the seed rather than hard-coding it, so the test does
        # not depend on the exact benchmark->H/s seed formula (which this change does not touch).
        dummy_agent, task, agent = self._setup_normal_task_agent(runtime_benchmark=0.005)

        BASE_RATE = self._chunk_speed(task, agent)
        self.assertGreater(BASE_RATE, 0, "precondition: runtime benchmark must seed a positive chunkSpeed")
        RULE_INFLATED_SPEED = BASE_RATE * RULE_COUNT  # what a 16-rule attack reports as raw hashcat H/s
        chunk_len = int(dummy_agent.chunk['length'])
        self.assertEqual(chunk_len, BASE_RATE * CHUNK_TIME,
                         "precondition: first chunk length must be the seeded chunkSpeed * chunkTime")

        # The first report's interval is skipped server-side (a freshly dispatched chunk has solveTime=0,
        # the multi-GPU-autotune-contaminated bootstrap). The next two are MEASURED intervals: keyspace
        # advances ~30% of the chunk (~BASE_RATE*3 base words) each, while the reported raw rate stays
        # inflated. A real ~3s gap makes the server-side (checkpoint, solveTime) delta resolve to
        # ~BASE_RATE base-words/s -- NOT the inflated raw rate.
        for progress in (10, 40, 70):   # kp advances 30% of the chunk per step, staying inside it
            dummy_agent.send_process(progress=progress, state=ProcessState.RUNNING,
                                     speed=RULE_INFLATED_SPEED)
            time.sleep(MEASURE_GAP_SECONDS)

        chunk_speed = self._chunk_speed(task, agent)
        self.assertLess(chunk_speed, RULE_INFLATED_SPEED / 4,
                        f"chunkSpeed {chunk_speed} tracked the raw hash-rate {RULE_INFLATED_SPEED} "
                        f"instead of the base-word rate ~{BASE_RATE}")
        self.assertLessEqual(chunk_speed, BASE_RATE * 2,
                             f"chunkSpeed {chunk_speed} is oversized vs the base-word rate {BASE_RATE}")
        self.assertGreaterEqual(chunk_speed, BASE_RATE * 0.3,
                                f"chunkSpeed {chunk_speed} collapsed below the base-word rate {BASE_RATE}")

    # ----------------------------------------------------- reconcile wiring (upward)

    def test_chunk_speed_reconciles_from_observed_keyspace_progress(self):
        """End-to-end wiring: the canonical chunkSpeed reconciles UP from a low benchmark seed toward the
        observed base-word rate derived from the keyspace-progress delta -- and NOT toward the (absurdly
        high) raw hash-rate the agent reports, proving the signal is keyspace progress, not raw H/s.
        """
        dummy_agent, task, agent = self._setup_normal_task_agent()   # low seed, small first chunk
        seed = self._chunk_speed(task, agent)
        self.assertGreater(seed, 0)

        huge_raw = seed * 1000   # an absurd raw hash-rate the reconcile must ignore

        # First report (progress 20) only establishes checkpoint+solveTime; its own interval is skipped
        # because a freshly dispatched chunk has solveTime=0. The second report (progress 90), a real
        # >=1s later, is the first MEASURED interval: base-words advanced / elapsed seconds, comfortably
        # above the low seed -> exactly one upward reconcile step.
        dummy_agent.send_process(progress=20, state=ProcessState.RUNNING, speed=huge_raw)
        time.sleep(MEASURE_GAP_SECONDS)
        dummy_agent.send_process(progress=90, state=ProcessState.RUNNING, speed=huge_raw)

        reconciled = self._chunk_speed(task, agent)
        self.assertGreater(reconciled, seed,
                           "chunkSpeed must reconcile UP from the seed toward the observed base-word rate")
        # A single upward EWMA step from the seed is clamped to <= seed * RECONCILE_MAX_UP_RATIO (4x); the
        # blended result is well under 3x the seed. The buggy raw-rate reconcile (no skip + raw H/s) would
        # take two steps and land far above this, so the bound is also a regression guard.
        self.assertLess(reconciled, seed * 3,
                        f"chunkSpeed {reconciled} grew faster than one base-word reconcile step from "
                        f"seed {seed} -- it tracked the raw hash-rate, not keyspace progress")

    # ----------------------------------------------------- config toggle (off = legacy)

    def test_adaptive_chunk_sizing_toggle_off_freezes_seed(self):
        """With the ``adaptiveChunkSizing`` config disabled, the reconcile write is gated off and
        chunkSpeed stays frozen at the benchmark seed (exact legacy frozen-benchmark sizing), even across
        a clean measured interval that WOULD reconcile while the toggle is on.
        """
        cfg = Config.objects.get(item='adaptiveChunkSizing')
        original = cfg.value
        cfg.value = "0"
        cfg.save()
        try:
            dummy_agent, task, agent = self._setup_normal_task_agent()
            seed = self._chunk_speed(task, agent)
            self.assertGreater(seed, 0)

            dummy_agent.send_process(progress=20, state=ProcessState.RUNNING, speed=seed * 50)
            time.sleep(MEASURE_GAP_SECONDS)
            dummy_agent.send_process(progress=90, state=ProcessState.RUNNING, speed=seed * 50)

            self.assertEqual(self._chunk_speed(task, agent), seed,
                             "chunkSpeed must stay frozen at the seed while adaptive sizing is disabled")
        finally:
            cfg.value = original
            cfg.save()

    # ------------------------------------------------------------- persistence

    def test_chunks_persisted_for_normal_task(self):
        """The dispatched chunks are real, persisted Chunk rows for the task (not phantom responses)."""
        dummy_agent, task, agent = self._setup_normal_task_agent()
        for _ in range(3):
            dummy_agent.send_process(progress=50, state=ProcessState.RUNNING)
            dummy_agent.send_process(progress=100, state=ProcessState.EXHAUSTED)
            dummy_agent.get_chunk()

        chunks = list(Chunk.objects.filter(taskId=task.id))
        # 1 seed chunk + 3 advanced chunks dispatched; allow >= to stay robust to any extra bookkeeping.
        self.assertGreaterEqual(len(chunks), 4,
                                f"expected at least 4 dispatched chunks, got {len(chunks)}")
