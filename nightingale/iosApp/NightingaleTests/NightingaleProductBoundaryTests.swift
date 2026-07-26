import XCTest
@testable import Nightingale

final class NightingaleProductBoundaryTests: XCTestCase {
    func testFoundationHasNoLivePatientOrStaffAccess() {
        XCTAssertEqual(NightingaleProductBoundary.productName, "Nightingale")
        XCTAssertFalse(NightingaleProductBoundary.livePatientAccessEnabled)
        XCTAssertFalse(NightingaleProductBoundary.staffEndpointsPermitted)
    }
}
