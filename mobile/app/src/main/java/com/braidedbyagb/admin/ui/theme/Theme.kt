package com.braidedbyagb.admin.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

// BraidedbyAGB brand colours
val Primary       = Color(0xFFCC1A8A)   // hot pink/magenta — buttons, accents
val PrimaryDark   = Color(0xFFA8146E)   // hover / dark variant
val Gold          = Color(0xFFF0C030)   // loyalty points
val ScreenBg      = Color(0xFFF5F0F8)   // light lavender page background
val TextMain      = Color(0xFF2A0020)   // dark purple body text
val TextMuted     = Color(0xFF7A4A70)   // muted purple secondary text
val Success       = Color(0xFF16A34A)   // green
val Danger        = Color(0xFFDC2626)   // red

// Legacy aliases kept for any screens that still reference them
val Purple800     = Color(0xFF2A0020)
val Purple600     = Color(0xFF7A4A70)
val PurpleLight   = Color(0xFFFAF5FF)

private val AppColorScheme = lightColorScheme(
    primary             = Primary,
    onPrimary           = Color.White,
    primaryContainer    = PurpleLight,
    onPrimaryContainer  = TextMain,
    secondary           = PrimaryDark,
    onSecondary         = Color.White,
    tertiary            = Gold,
    background          = ScreenBg,
    surface             = Color.White,
    onSurface           = TextMain,
    onSurfaceVariant    = TextMuted,
    error               = Danger
)

@Composable
fun BraidedByAGBTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = AppColorScheme,
        content     = content
    )
}
