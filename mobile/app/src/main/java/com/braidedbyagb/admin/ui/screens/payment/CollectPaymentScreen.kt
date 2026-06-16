package com.braidedbyagb.admin.ui.screens.payment

import android.Manifest
import android.nfc.NfcAdapter
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CreditCard
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.model.BookingDetail
import com.braidedbyagb.admin.data.model.TerminalConfirmRequest
import com.braidedbyagb.admin.data.model.TerminalPaymentRequest
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.ui.theme.Success
import com.braidedbyagb.admin.ui.theme.TextMuted
import com.stripe.stripeterminal.Terminal
import com.stripe.stripeterminal.external.callable.Callback
import com.stripe.stripeterminal.external.callable.ConnectionTokenCallback
import com.stripe.stripeterminal.external.callable.ConnectionTokenProvider
import com.stripe.stripeterminal.external.callable.DiscoveryListener
import com.stripe.stripeterminal.external.callable.OfflineListener
import com.stripe.stripeterminal.external.callable.PaymentIntentCallback
import com.stripe.stripeterminal.external.callable.ReaderCallback
import com.stripe.stripeterminal.external.callable.TerminalListener
import com.stripe.stripeterminal.external.models.ConnectionConfiguration
import com.stripe.stripeterminal.external.models.ConnectionStatus
import com.stripe.stripeterminal.external.models.ConnectionTokenException
import com.stripe.stripeterminal.external.models.DiscoveryConfiguration
import com.stripe.stripeterminal.external.models.OfflineStatus
import com.stripe.stripeterminal.external.models.PaymentIntent
import com.stripe.stripeterminal.external.models.PaymentStatus
import com.stripe.stripeterminal.external.models.Reader
import com.stripe.stripeterminal.external.models.TapUseCase
import com.stripe.stripeterminal.external.models.TerminalException
import com.stripe.stripeterminal.log.LogLevel
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

// ── Payment state machine ─────────────────────────────────────────────────
enum class TapPayState {
    LOADING, SELECT_TYPE, IDLE,
    REQUESTING_PERMS, DISCOVERING, CONNECTING,
    CREATING_INTENT, WAITING_FOR_TAP, PROCESSING, CONFIRMING,
    SUCCESS, ERROR
}

// ── Stripe Terminal v5 suspend wrappers ───────────────────────────────────

/** Discover the Tap to Pay (NFC) reader built into this Android device. */
private suspend fun discoverTapToPayReader(): Reader =
    suspendCancellableCoroutine { cont ->
        val config = DiscoveryConfiguration.TapToPayDiscoveryConfiguration(isSimulated = false)
        var found: Reader? = null
        val listener = object : DiscoveryListener {
            override fun onUpdateDiscoveredReaders(readers: List<Reader>) {
                if (readers.isNotEmpty() && found == null && cont.isActive) {
                    found = readers.first()
                    cont.resume(readers.first())
                }
            }
        }
        val cancelable = Terminal.getInstance().discoverReaders(
            config, listener,
            object : Callback {
                override fun onSuccess() {}
                override fun onFailure(e: TerminalException) {
                    if (cont.isActive) cont.resumeWithException(e)
                }
            }
        )
        cont.invokeOnCancellation {
            cancelable.cancel(object : Callback {
                override fun onSuccess() {}
                override fun onFailure(e: TerminalException) {}
            })
        }
    }

/** Connect to the discovered Tap to Pay reader. */
private suspend fun connectTapToPayReader(reader: Reader): Reader =
    suspendCancellableCoroutine { cont ->
        // TapUseCase.Pay associates the reader with a Stripe location (empty = account default)
        val config = ConnectionConfiguration.TapToPayConnectionConfiguration(
            TapUseCase.Pay(TerminalState.locationId)
        )
        Terminal.getInstance().connectReader(reader, config,
            object : ReaderCallback {
                override fun onSuccess(r: Reader) {
                    if (cont.isActive) cont.resume(r)
                }
                override fun onFailure(e: TerminalException) {
                    if (cont.isActive) cont.resumeWithException(e)
                }
            }
        )
    }

private suspend fun retrievePaymentIntent(clientSecret: String): PaymentIntent =
    suspendCancellableCoroutine { cont ->
        Terminal.getInstance().retrievePaymentIntent(clientSecret,
            object : PaymentIntentCallback {
                override fun onSuccess(pi: PaymentIntent) {
                    if (cont.isActive) cont.resume(pi)
                }
                override fun onFailure(e: TerminalException) {
                    if (cont.isActive) cont.resumeWithException(e)
                }
            }
        )
    }

private suspend fun collectPaymentMethod(pi: PaymentIntent): PaymentIntent =
    suspendCancellableCoroutine { cont ->
        val cancelable = Terminal.getInstance().collectPaymentMethod(pi,
            object : PaymentIntentCallback {
                override fun onSuccess(collected: PaymentIntent) {
                    if (cont.isActive) cont.resume(collected)
                }
                override fun onFailure(e: TerminalException) {
                    if (cont.isActive) cont.resumeWithException(e)
                }
            }
        )
        cont.invokeOnCancellation {
            cancelable.cancel(object : Callback {
                override fun onSuccess() {}
                override fun onFailure(e: TerminalException) {}
            })
        }
    }

/** Confirm (process) the collected PaymentIntent on device. */
private suspend fun confirmPaymentIntentOnDevice(pi: PaymentIntent): PaymentIntent =
    suspendCancellableCoroutine { cont ->
        val cancelable = Terminal.getInstance().confirmPaymentIntent(pi,
            object : PaymentIntentCallback {
                override fun onSuccess(processed: PaymentIntent) {
                    if (cont.isActive) cont.resume(processed)
                }
                override fun onFailure(e: TerminalException) {
                    if (cont.isActive) cont.resumeWithException(e)
                }
            }
        )
        cont.invokeOnCancellation {
            cancelable.cancel(object : Callback {
                override fun onSuccess() {}
                override fun onFailure(e: TerminalException) {}
            })
        }
    }

// ── Connection token provider ─────────────────────────────────────────────
private class AdminConnectionTokenProvider : ConnectionTokenProvider {
    override fun fetchConnectionToken(listener: ConnectionTokenCallback) {
        CoroutineScope(Dispatchers.IO).launch {
            try {
                val res = ApiClient.api.getTerminalConnectionToken()
                if (res.isSuccessful && res.body() != null) {
                    val body = res.body()!!
                    // Store location ID so it can be used in the connection configuration
                    TerminalState.locationId = body.locationId
                    listener.onSuccess(body.secret)
                } else {
                    listener.onFailure(ConnectionTokenException("Server error ${res.code()}"))
                }
            } catch (e: Exception) {
                listener.onFailure(ConnectionTokenException(e.message ?: "Connection failed"))
            }
        }
    }
}

// ── Terminal state singleton ──────────────────────────────────────────────
private object TerminalState {
    var locationId:   String  = ""
    var initialized:  Boolean = false
}

// ── Offline listener (no-op for non-offline usage) ───────────────────────
private val noOpOfflineListener = object : OfflineListener {
    override fun onOfflineStatusChange(offlineStatus: OfflineStatus) {}
    override fun onPaymentIntentForwarded(intent: PaymentIntent, e: TerminalException?) {}
    override fun onForwardingFailure(e: TerminalException) {}
}

// ── Screen ────────────────────────────────────────────────────────────────
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CollectPaymentScreen(
    bookingId: Int,
    onBack:    () -> Unit,
    onSuccess: () -> Unit
) {
    val scope   = rememberCoroutineScope()
    val context = LocalContext.current

    var booking      by remember { mutableStateOf<BookingDetail?>(null) }
    var tapPayState  by remember { mutableStateOf(TapPayState.LOADING) }
    var selectedType by remember { mutableStateOf("balance") }
    var amountGbp    by remember { mutableStateOf(0.0) }
    var amountPence  by remember { mutableStateOf(0) }
    var statusMsg    by remember { mutableStateOf("") }
    var errorMsg     by remember { mutableStateOf("") }

    // Load booking details
    LaunchedEffect(Unit) {
        val res = ApiClient.api.getBooking(bookingId)
        if (res.isSuccessful) {
            booking = res.body()
            // Default: collect balance if deposit already paid, otherwise deposit
            selectedType = if ((booking?.depositPaid ?: 0) == 1) "balance" else "deposit"
            tapPayState  = TapPayState.SELECT_TYPE
        } else {
            errorMsg    = "Failed to load booking"
            tapPayState = TapPayState.ERROR
        }
    }

    // ── Permission launcher ───────────────────────────────────
    // Tap to Pay only needs Location — Bluetooth permissions are for hardware BT readers, not NFC
    val permissionsToRequest = arrayOf(Manifest.permission.ACCESS_FINE_LOCATION)

    val permissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { perms ->
        val locationGranted = perms[Manifest.permission.ACCESS_FINE_LOCATION] == true
        if (locationGranted) {
            scope.launch {
                runPaymentFlow(
                    bookingId      = bookingId,
                    paymentType    = selectedType,
                    context        = context,
                    onStateChange  = { tapPayState = it },
                    onStatusChange = { statusMsg = it },
                    onAmountSet    = { gbp, pence -> amountGbp = gbp; amountPence = pence },
                    onError        = { msg -> errorMsg = msg; tapPayState = TapPayState.ERROR },
                    onSuccess      = { onSuccess() }
                )
            }
        } else {
            errorMsg    = "Location permission is required for Tap to Pay"
            tapPayState = TapPayState.ERROR
        }
    }

    // ── UI ────────────────────────────────────────────────────
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Collect Card Payment", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, null)
                    }
                }
            )
        }
    ) { padding ->
        Column(
            modifier            = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(20.dp)
        ) {
            val bk = booking

            when (tapPayState) {

                // ── Loading ───────────────────────────────────
                TapPayState.LOADING -> {
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(color = Primary)
                    }
                }

                // ── Select payment type ───────────────────────
                TapPayState.SELECT_TYPE -> if (bk != null) {
                    Text("What to collect?",
                         fontWeight = FontWeight.Bold, fontSize = 18.sp, color = Purple800)
                    Text("${bk.clientName} · ${bk.serviceName}",
                         fontSize = 14.sp, color = TextMuted)

                    Spacer(Modifier.height(8.dp))

                    // Deposit — only when not yet paid
                    if (bk.depositPaid == 0) {
                        PaymentTypeCard(
                            label    = "Deposit",
                            amount   = bk.depositAmount,
                            selected = selectedType == "deposit",
                            onClick  = { selectedType = "deposit" }
                        )
                    }

                    // Remaining balance — only when deposit has been paid
                    val balance = bk.totalPrice - bk.depositAmount
                    if (bk.depositPaid == 1 && balance > 0.01) {
                        PaymentTypeCard(
                            label    = "Remaining Balance",
                            amount   = balance,
                            selected = selectedType == "balance",
                            onClick  = { selectedType = "balance" }
                        )
                    }

                    // Full payment — when nothing paid yet
                    if (bk.depositPaid == 0) {
                        PaymentTypeCard(
                            label    = "Full Payment",
                            amount   = bk.totalPrice,
                            selected = selectedType == "full",
                            onClick  = { selectedType = "full" }
                        )
                    }

                    Spacer(Modifier.weight(1f))

                    Button(
                        onClick = {
                            tapPayState = TapPayState.IDLE
                            permissionLauncher.launch(permissionsToRequest)
                        },
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(52.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = Primary)
                    ) {
                        Icon(Icons.Default.CreditCard, null, Modifier.size(20.dp))
                        Spacer(Modifier.width(8.dp))
                        Text("Start Card Collection",
                             fontWeight = FontWeight.Bold, fontSize = 16.sp)
                    }
                }

                // ── In-progress / setup states ────────────────
                TapPayState.IDLE,
                TapPayState.REQUESTING_PERMS,
                TapPayState.DISCOVERING,
                TapPayState.CONNECTING,
                TapPayState.CREATING_INTENT -> {
                    Spacer(Modifier.weight(1f))
                    CircularProgressIndicator(
                        color     = Primary,
                        modifier  = Modifier.size(56.dp),
                        strokeWidth = 4.dp
                    )
                    Spacer(Modifier.height(24.dp))
                    Text(
                        text      = statusMsg.ifBlank { "Preparing…" },
                        fontSize  = 16.sp,
                        textAlign = TextAlign.Center,
                        color     = TextMuted
                    )
                    Spacer(Modifier.weight(1f))
                }

                // ── Waiting for card tap ──────────────────────
                TapPayState.WAITING_FOR_TAP -> {
                    Spacer(Modifier.weight(1f))
                    Icon(Icons.Default.CreditCard, null,
                         modifier = Modifier.size(80.dp),
                         tint = Primary)
                    Spacer(Modifier.height(16.dp))
                    Text(
                        text       = "£%.2f".format(amountGbp),
                        fontWeight = FontWeight.ExtraBold,
                        fontSize   = 40.sp,
                        color      = Purple800
                    )
                    Spacer(Modifier.height(8.dp))
                    Text(
                        text      = "Ask the customer to tap\ntheir card on the back of the phone",
                        fontSize  = 16.sp,
                        textAlign = TextAlign.Center,
                        color     = TextMuted,
                        lineHeight = 24.sp
                    )
                    Spacer(Modifier.height(24.dp))
                    LinearProgressIndicator(
                        color    = Primary,
                        modifier = Modifier.fillMaxWidth()
                    )
                    Spacer(Modifier.weight(1f))
                }

                // ── Processing / confirming ───────────────────
                TapPayState.PROCESSING,
                TapPayState.CONFIRMING -> {
                    Spacer(Modifier.weight(1f))
                    CircularProgressIndicator(
                        color     = Primary,
                        modifier  = Modifier.size(56.dp),
                        strokeWidth = 4.dp
                    )
                    Spacer(Modifier.height(24.dp))
                    Text(
                        text      = if (tapPayState == TapPayState.PROCESSING) "Processing payment…"
                                    else "Recording payment…",
                        fontSize  = 16.sp,
                        textAlign = TextAlign.Center,
                        color     = TextMuted
                    )
                    Spacer(Modifier.weight(1f))
                }

                // ── Success ───────────────────────────────────
                TapPayState.SUCCESS -> {
                    Spacer(Modifier.weight(1f))
                    Text("✓", fontSize = 72.sp, color = Success)
                    Spacer(Modifier.height(8.dp))
                    Text("Payment Successful",
                         fontWeight = FontWeight.Bold, fontSize = 22.sp, color = Purple800)
                    Text("£%.2f collected".format(amountGbp),
                         fontSize = 16.sp, color = TextMuted)
                    if (selectedType in listOf("balance", "full")) {
                        Spacer(Modifier.height(8.dp))
                        Text("Booking completed · Loyalty points awarded",
                             fontSize = 13.sp, color = Success)
                    }
                    Spacer(Modifier.weight(1f))
                    Button(
                        onClick  = onSuccess,
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        colors   = ButtonDefaults.buttonColors(containerColor = Primary)
                    ) { Text("Back to Booking", fontWeight = FontWeight.Bold) }
                }

                // ── Error ─────────────────────────────────────
                TapPayState.ERROR -> {
                    Spacer(Modifier.weight(1f))
                    Text("⚠", fontSize = 56.sp)
                    Spacer(Modifier.height(8.dp))
                    Text("Payment Failed",
                         fontWeight = FontWeight.Bold, fontSize = 20.sp,
                         color = MaterialTheme.colorScheme.error)
                    Text(errorMsg,
                         fontSize = 14.sp, color = TextMuted,
                         textAlign = TextAlign.Center)
                    Spacer(Modifier.weight(1f))
                    Button(
                        onClick = {
                            errorMsg    = ""
                            tapPayState = TapPayState.SELECT_TYPE
                        },
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        colors   = ButtonDefaults.buttonColors(containerColor = Primary)
                    ) { Text("Try Again") }
                    Spacer(Modifier.height(8.dp))
                    OutlinedButton(
                        onClick  = onBack,
                        modifier = Modifier.fillMaxWidth()
                    ) { Text("Cancel") }
                }
            }
        }
    }
}

// ── Payment type selector card ────────────────────────────────────────────
@Composable
private fun PaymentTypeCard(
    label:    String,
    amount:   Double,
    selected: Boolean,
    onClick:  () -> Unit
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        onClick  = onClick,
        colors   = CardDefaults.cardColors(
            containerColor = if (selected) Primary.copy(alpha = 0.08f)
                             else MaterialTheme.colorScheme.surface
        ),
        border = if (selected)
            androidx.compose.foundation.BorderStroke(2.dp, Primary)
        else null
    ) {
        Row(
            modifier          = Modifier.padding(16.dp).fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(Modifier.weight(1f)) {
                Text(label, fontWeight = FontWeight.Medium, fontSize = 15.sp)
            }
            Text(
                text       = "£%.2f".format(amount),
                fontWeight = FontWeight.Bold,
                fontSize   = 18.sp,
                color      = if (selected) Primary else Purple800
            )
        }
    }
}

// ── Full payment flow (runs in a coroutine) ───────────────────────────────
private suspend fun runPaymentFlow(
    bookingId:     Int,
    paymentType:   String,
    context:       android.content.Context,
    onStateChange: (TapPayState) -> Unit,
    onStatusChange:(String) -> Unit,
    onAmountSet:   (Double, Int) -> Unit,
    onError:       (String) -> Unit,
    onSuccess:     () -> Unit
) {
    try {
        // 0. Check NFC hardware is present and enabled — gives a clear error on unsupported tablets
        val nfc = NfcAdapter.getDefaultAdapter(context)
        if (nfc == null) {
            onError("This device does not have NFC hardware. Tap to Pay requires an NFC-enabled device.")
            return
        }
        if (!nfc.isEnabled) {
            onError("NFC is turned off. Please enable NFC in Settings → Connected devices and try again.")
            return
        }

        // 1. Initialise Terminal SDK (once per app lifecycle).
        //    Wrap in try/catch: if a previous flow crashed after init but before
        //    setting the flag, the SDK is already initialised and will throw.
        if (!TerminalState.initialized) {
            onStateChange(TapPayState.DISCOVERING)
            onStatusChange("Initialising card reader…")
            try {
                Terminal.init(
                    context.applicationContext,
                    LogLevel.NONE,
                    AdminConnectionTokenProvider(),
                    object : TerminalListener {
                        override fun onConnectionStatusChange(status: ConnectionStatus) {}
                        override fun onPaymentStatusChange(status: PaymentStatus) {}
                    },
                    noOpOfflineListener
                )
            } catch (e: IllegalStateException) {
                // SDK was already initialised from a previous session — safe to continue
            }
            TerminalState.initialized = true
        }

        // 2. Discover + connect only if not already connected (second payment in same session)
        val alreadyConnected = try {
            Terminal.getInstance().connectedReader != null
        } catch (_: Exception) { false }

        if (!alreadyConnected) {
            onStateChange(TapPayState.DISCOVERING)
            onStatusChange("Looking for NFC reader on this device…")
            val reader = discoverTapToPayReader()

            onStateChange(TapPayState.CONNECTING)
            onStatusChange("Connecting to reader…")
            connectTapToPayReader(reader)
        } else {
            onStatusChange("Reader ready…")
        }

        // 4. Create PaymentIntent on server
        onStateChange(TapPayState.CREATING_INTENT)
        onStatusChange("Preparing payment…")
        val piRes = ApiClient.api.createTerminalPaymentIntent(
            bookingId,
            TerminalPaymentRequest(paymentType)
        )
        if (!piRes.isSuccessful || piRes.body() == null) {
            onError(
                piRes.errorBody()?.string()?.let { extractError(it) }
                    ?: "Failed to create payment"
            )
            return
        }
        val piBody = piRes.body()!!
        onAmountSet(piBody.amountGbp, piBody.amountPence)

        // 5. Retrieve PaymentIntent via SDK
        val pi = retrievePaymentIntent(piBody.clientSecret)

        // 6. Present NFC tap UI — waits for customer card tap
        onStateChange(TapPayState.WAITING_FOR_TAP)
        onStatusChange("Waiting for card tap…")
        val collectedPi = collectPaymentMethod(pi)

        // 7. Confirm payment on device
        onStateChange(TapPayState.PROCESSING)
        onStatusChange("Processing…")
        val processedPi = confirmPaymentIntentOnDevice(collectedPi)

        // 8. Record on server — updates booking, awards loyalty points, journal entry
        onStateChange(TapPayState.CONFIRMING)
        onStatusChange("Recording payment…")
        val confirmRes = ApiClient.api.confirmTerminalPayment(
            bookingId,
            TerminalConfirmRequest(
                paymentIntentId = processedPi.id ?: piBody.paymentIntentId,
                type            = paymentType,
                amountPence     = piBody.amountPence
            )
        )
        if (!confirmRes.isSuccessful) {
            onError(
                "Payment processed but not recorded — contact support" +
                " (PI: ${processedPi.id ?: piBody.paymentIntentId})"
            )
            return
        }

        // 9. Done
        onStateChange(TapPayState.SUCCESS)
        onSuccess()

    } catch (e: TerminalException) {
        onError(e.errorMessage.ifBlank { e.errorCode.name })
    } catch (e: Exception) {
        onError(e.message ?: "Unexpected error")
    }
}

private fun extractError(json: String): String =
    try {
        org.json.JSONObject(json).optString("error", "Server error")
    } catch (_: Exception) {
        "Server error"
    }
