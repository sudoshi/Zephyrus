package net.acumenus.nightingale

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

class NightingaleProductBoundaryTest {
    @Test
    fun foundationHasNoLivePatientOrStaffAccess() {
        assertEquals("Nightingale", NightingaleProductBoundary.productName)
        assertFalse(NightingaleProductBoundary.livePatientAccessEnabled)
        assertFalse(NightingaleProductBoundary.staffEndpointsPermitted)
    }
}
