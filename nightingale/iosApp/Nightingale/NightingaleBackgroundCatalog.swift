import Foundation

enum NightingaleBackgroundCatalog {
    static let assetNames = [
        "nightingale_background_01",
        "nightingale_background_02",
        "nightingale_background_03",
        "nightingale_background_04",
        "nightingale_background_05",
        "nightingale_background_06",
        "nightingale_background_07",
    ]

    static func index(
        for date: Date,
        calendar: Calendar = .autoupdatingCurrent
    ) -> Int {
        guard !assetNames.isEmpty else {
            return 0
        }

        return positiveModulo(
            localGregorianEpochDay(for: date, calendar: calendar),
            divisor: assetNames.count
        )
    }

    static func assetName(
        for date: Date = Date(),
        calendar: Calendar = .autoupdatingCurrent
    ) -> String {
        assetNames[index(for: date, calendar: calendar)]
    }

    private static func positiveModulo(_ value: Int, divisor: Int) -> Int {
        let remainder = value % divisor
        return remainder >= 0 ? remainder : remainder + divisor
    }

    private static func localGregorianEpochDay(
        for date: Date,
        calendar: Calendar
    ) -> Int {
        var gregorian = Calendar(identifier: .gregorian)
        gregorian.timeZone = calendar.timeZone
        guard
            let referenceDate = gregorian.date(
                from: DateComponents(year: 1970, month: 1, day: 1)
            ),
            let epochDay = gregorian.dateComponents(
                [.day],
                from: referenceDate,
                to: gregorian.startOfDay(for: date)
            ).day
        else {
            return 0
        }

        return epochDay
    }
}
