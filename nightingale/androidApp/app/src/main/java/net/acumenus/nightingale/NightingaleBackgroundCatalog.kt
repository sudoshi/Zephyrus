package net.acumenus.nightingale

internal object NightingaleBackgroundCatalog {
    val resourceIds = listOf(
        R.drawable.nightingale_background_01,
        R.drawable.nightingale_background_02,
        R.drawable.nightingale_background_03,
        R.drawable.nightingale_background_04,
        R.drawable.nightingale_background_05,
        R.drawable.nightingale_background_06,
        R.drawable.nightingale_background_07,
    )

    fun indexForEpochDay(epochDay: Long): Int =
        Math.floorMod(epochDay, resourceIds.size.toLong()).toInt()

    fun resourceIdForEpochDay(epochDay: Long): Int =
        resourceIds[indexForEpochDay(epochDay)]
}
