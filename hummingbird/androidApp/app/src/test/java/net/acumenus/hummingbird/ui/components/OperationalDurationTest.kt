package net.acumenus.hummingbird.ui.components

import java.time.Instant
import org.junit.Assert.assertEquals
import org.junit.Test

class OperationalDurationTest {
    @Test
    fun decomposesFractionalMinutesToWholeHoursMinutesAndSeconds() {
        assertEquals("1 hr 31 min 31 sec", formatOperationalMinutes(91.5166666667))
        assertEquals("2 min 0 sec", formatOperationalMinutes(2))
        assertEquals("0 sec", formatOperationalMinutes(0))
        assertEquals("0 sec", formatOperationalSeconds(-0.4))
    }

    @Test
    fun usesCompactWholeSecondOffsetsForTheFlowScrubber() {
        assertEquals("-1h 1m 31s", formatOperationalOffset(-3_691_000))
        assertEquals("+31s", formatOperationalOffset(30_600))
        assertEquals("now", formatOperationalOffset(499))
    }

    @Test
    fun formatsOperationalAgeWithoutDroppingRemainderUnits() {
        val now = Instant.parse("2026-07-09T12:00:00Z")
        assertEquals(
            "1 hr 1 min 31 sec ago",
            formatOperationalAge(Instant.parse("2026-07-09T10:58:29Z"), now),
        )
        assertEquals("scheduled", formatOperationalAge(Instant.parse("2026-07-09T12:00:01Z"), now))
    }
}
