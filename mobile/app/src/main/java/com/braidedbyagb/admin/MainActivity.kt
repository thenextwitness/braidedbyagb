package com.braidedbyagb.admin

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.material.icons.automirrored.filled.Chat
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import kotlinx.coroutines.delay
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.*
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.screens.accounting.AccountingScreen
import com.braidedbyagb.admin.ui.screens.bookings.BookingDetailScreen
import com.braidedbyagb.admin.ui.screens.bookings.BookingsScreen
import com.braidedbyagb.admin.ui.screens.bookings.CreateBookingScreen
import com.braidedbyagb.admin.ui.screens.calendar.CalendarScreen
import com.braidedbyagb.admin.ui.screens.payment.CollectPaymentScreen
import com.braidedbyagb.admin.ui.screens.customers.CustomerDetailScreen
import com.braidedbyagb.admin.ui.screens.customers.CustomersScreen
import com.braidedbyagb.admin.ui.screens.dashboard.DashboardScreen
import com.braidedbyagb.admin.ui.screens.login.LoginScreen
import com.braidedbyagb.admin.ui.screens.orders.OrdersScreen
import com.braidedbyagb.admin.ui.screens.discounts.DiscountsScreen
import com.braidedbyagb.admin.ui.screens.requests.CustomRequestsScreen
import com.braidedbyagb.admin.ui.screens.reviews.ReviewsScreen
import com.braidedbyagb.admin.ui.screens.services.ServiceEditScreen
import com.braidedbyagb.admin.ui.screens.services.ServicesScreen
import com.braidedbyagb.admin.ui.screens.settings.SettingsScreen
import com.braidedbyagb.admin.ui.screens.chat.ChatSessionsScreen
import com.braidedbyagb.admin.ui.screens.chat.ChatDetailScreen
import com.braidedbyagb.admin.service.FcmService
import com.braidedbyagb.admin.ui.theme.BraidedByAGBTheme

sealed class Screen(val route: String, val label: String, val icon: ImageVector) {
    object Dashboard   : Screen("dashboard",   "Dashboard",  Icons.Default.Home)
    object Bookings    : Screen("bookings",    "Bookings",   Icons.Default.DateRange)
    object Customers   : Screen("customers",   "Clients",    Icons.Default.People)
    object Accounting  : Screen("accounting",  "Finance",    Icons.Default.AccountBalance)
    object Chat        : Screen("chat",        "Chat",       Icons.AutoMirrored.Filled.Chat)
    object Settings    : Screen("settings",    "Settings",   Icons.Default.Settings)
}

// Secondary screens (not in bottom nav, reachable via navigation)
object CalendarRoute   { const val route = "calendar" }
object OrdersRoute     { const val route = "orders" }
object ServicesRoute   { const val route = "services" }
object ReviewsRoute    { const val route = "reviews" }
object RequestsRoute   { const val route = "custom_requests" }
object DiscountsRoute  { const val route = "discounts" }
object ChatRoute       { const val route = "chat" }

val NAV_ITEMS = listOf(
    Screen.Dashboard, Screen.Bookings, Screen.Customers,
    Screen.Chat, Screen.Settings
)

class MainActivity : ComponentActivity() {

    // Pending chat session to open from a notification tap
    private var pendingChatSessionId = mutableStateOf<Int?>(null)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        ApiClient.init(this)
        enableEdgeToEdge()

        // Handle notification tap when app was killed
        pendingChatSessionId.value =
            intent?.getIntExtra(FcmService.EXTRA_SESSION_ID, -1)?.takeIf { it > 0 }

        // Register/refresh the FCM token every launch
        FcmService.refreshAndRegisterToken()

        setContent {
            BraidedByAGBTheme {
                AppRoot(pendingChatSessionId = pendingChatSessionId.value) {
                    pendingChatSessionId.value = null
                }
            }
        }
    }

    // Handle notification tap when app is already running in foreground/background
    override fun onNewIntent(intent: android.content.Intent) {
        super.onNewIntent(intent)
        val sessionId = intent.getIntExtra(FcmService.EXTRA_SESSION_ID, -1).takeIf { it > 0 }
        pendingChatSessionId.value = sessionId
    }
}

@Composable
fun AppRoot(
    pendingChatSessionId: Int? = null,
    onPendingChatConsumed: () -> Unit = {}
) {
    var isLoggedIn by remember { mutableStateOf(ApiClient.hasToken()) }

    if (!isLoggedIn) {
        LoginScreen(onLoginSuccess = { isLoggedIn = true })
        return
    }

    fun handleSignOut() {
        ApiClient.clearToken()
        isLoggedIn = false
    }

    val navController = rememberNavController()

    // Navigate to Chat sessions list when a notification is tapped
    LaunchedEffect(pendingChatSessionId) {
        pendingChatSessionId?.let { _ ->
            navController.navigate(ChatRoute.route) {
                popUpTo(navController.graph.findStartDestination().id) { saveState = true }
                launchSingleTop = true
            }
            onPendingChatConsumed()
        }
    }

    // Unread chat badge count — polled every 30 s
    var chatUnreadCount by remember { mutableStateOf(0) }
    LaunchedEffect(Unit) {
        while (true) {
            try {
                val res = ApiClient.api.getChatSessions(status = "active")
                if (res.isSuccessful) {
                    chatUnreadCount = res.body()?.count { it.unreadAdmin > 0 } ?: 0
                }
            } catch (_: Exception) {}
            delay(30_000)
        }
    }

    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = navBackStackEntry?.destination?.route

    // Determine which tab to show as selected, including when inside a child screen
    val selectedTab = when {
        currentRoute?.startsWith("booking_detail")  == true -> Screen.Bookings.route
        currentRoute == "create_booking"                    -> Screen.Bookings.route
        currentRoute?.startsWith("collect_payment") == true -> Screen.Bookings.route
        currentRoute == CalendarRoute.route                 -> Screen.Bookings.route
        currentRoute?.startsWith("customer_detail") == true -> Screen.Customers.route
        currentRoute == Screen.Accounting.route             -> Screen.Settings.route
        currentRoute == ServicesRoute.route                 -> Screen.Settings.route
        currentRoute == "service_create"                    -> Screen.Settings.route
        currentRoute?.startsWith("service_edit")    == true -> Screen.Settings.route
        currentRoute == ReviewsRoute.route                  -> Screen.Settings.route
        currentRoute == RequestsRoute.route                 -> Screen.Settings.route
        currentRoute == DiscountsRoute.route                -> Screen.Settings.route
        currentRoute == ChatRoute.route                     -> Screen.Chat.route
        currentRoute?.startsWith("chat_detail")     == true -> Screen.Chat.route
        else -> currentRoute
    }

    Scaffold(
        bottomBar = {
            NavigationBar {
                NAV_ITEMS.forEach { screen ->
                    NavigationBarItem(
                        icon = {
                            if (screen == Screen.Chat && chatUnreadCount > 0) {
                                BadgedBox(badge = {
                                    Badge { Text(chatUnreadCount.toString()) }
                                }) {
                                    Icon(screen.icon, contentDescription = screen.label)
                                }
                            } else {
                                Icon(screen.icon, contentDescription = screen.label)
                            }
                        },
                        label    = { Text(screen.label) },
                        selected = selectedTab == screen.route,
                        onClick  = {
                            navController.navigate(screen.route) {
                                popUpTo(navController.graph.findStartDestination().id) { saveState = true }
                                launchSingleTop = true
                                restoreState    = true
                            }
                        }
                    )
                }
            }
        }
    ) { padding ->
        NavHost(
            navController = navController,
            startDestination = Screen.Dashboard.route,
            modifier = Modifier.padding(padding)
        ) {
            composable(Screen.Dashboard.route) {
                DashboardScreen(onBookingClick = { id -> navController.navigate("booking_detail/$id") })
            }
            composable(Screen.Bookings.route) {
                BookingsScreen(
                    onBookingClick  = { id -> navController.navigate("booking_detail/$id") },
                    onCreateBooking = { navController.navigate("create_booking") },
                    onOpenCalendar  = { navController.navigate(CalendarRoute.route) }
                )
            }
            composable("booking_detail/{id}") { back ->
                val id = back.arguments?.getString("id")?.toIntOrNull() ?: return@composable
                BookingDetailScreen(
                    bookingId       = id,
                    onBack          = { navController.popBackStack() },
                    onCollectPayment = { bkId -> navController.navigate("collect_payment/$bkId") }
                )
            }
            composable("collect_payment/{id}") { back ->
                val id = back.arguments?.getString("id")?.toIntOrNull() ?: return@composable
                CollectPaymentScreen(
                    bookingId = id,
                    onBack    = { navController.popBackStack() },
                    onSuccess = { navController.navigate("booking_detail/$id") {
                        popUpTo("collect_payment/$id") { inclusive = true }
                    }}
                )
            }
            composable(Screen.Customers.route) {
                CustomersScreen(onCustomerClick = { id -> navController.navigate("customer_detail/$id") })
            }
            composable("customer_detail/{id}") { back ->
                val id = back.arguments?.getString("id")?.toIntOrNull() ?: return@composable
                CustomerDetailScreen(customerId = id, navController = navController)
            }
            composable(Screen.Accounting.route) {
                AccountingScreen(onBack = { navController.popBackStack() })
            }
            composable(Screen.Settings.route) {
                SettingsScreen(
                    onSignOut         = { handleSignOut() },
                    onManageServices  = { navController.navigate(ServicesRoute.route) },
                    onManageReviews   = { navController.navigate(ReviewsRoute.route) },
                    onManageRequests  = { navController.navigate(RequestsRoute.route) },
                    onManageDiscounts = { navController.navigate(DiscountsRoute.route) },
                    onManageChat      = { navController.navigate(ChatRoute.route) },
                    onOpenAccounting  = { navController.navigate(Screen.Accounting.route) }
                )
            }
            // Secondary screens — reachable via deep links / other nav actions, not in bottom bar
            composable("create_booking") {
                CreateBookingScreen(
                    onBack    = { navController.popBackStack() },
                    onCreated = { id -> navController.navigate("booking_detail/$id") {
                        popUpTo("create_booking") { inclusive = true }
                    }}
                )
            }
            composable(CalendarRoute.route)  { CalendarScreen() }
            composable(OrdersRoute.route)    { OrdersScreen() }
            composable(ReviewsRoute.route)   { ReviewsScreen() }
            composable(RequestsRoute.route)  { CustomRequestsScreen() }
            composable(DiscountsRoute.route) { DiscountsScreen() }
            composable(ServicesRoute.route)  {
                ServicesScreen(
                    onCreateService = { navController.navigate("service_create") },
                    onEditService   = { id -> navController.navigate("service_edit/$id") }
                )
            }
            // ── Live Chat ─────────────────────────────────────
            composable(ChatRoute.route) {
                ChatSessionsScreen(
                    onSessionClick = { id -> navController.navigate("chat_detail/$id") }
                )
            }
            composable("chat_detail/{id}") { back ->
                val id = back.arguments?.getString("id")?.toIntOrNull() ?: return@composable
                ChatDetailScreen(
                    sessionId = id,
                    onBack    = { navController.popBackStack() }
                )
            }
            composable("service_create") {
                ServiceEditScreen(
                    serviceId = null,
                    onBack    = { navController.popBackStack() },
                    onSaved   = { id ->
                        navController.navigate("service_edit/$id") {
                            popUpTo("service_create") { inclusive = true }
                        }
                    }
                )
            }
            composable("service_edit/{id}") { back ->
                val id = back.arguments?.getString("id")?.toIntOrNull() ?: return@composable
                ServiceEditScreen(
                    serviceId = id,
                    onBack    = { navController.popBackStack() },
                    onSaved   = { navController.popBackStack() }
                )
            }
        }
    }
}
