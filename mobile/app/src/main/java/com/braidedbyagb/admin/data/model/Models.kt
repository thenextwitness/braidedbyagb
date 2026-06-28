package com.braidedbyagb.admin.data.model

import com.google.gson.annotations.SerializedName

// ── Auth ─────────────────────────────────────────────────
data class AuthRequest(val email: String, val password: String)
data class AuthResponse(val token: String, @SerializedName("expires_in") val expiresIn: Int)

// ── Dashboard ────────────────────────────────────────────
data class DashboardStats(
    @SerializedName("pending_count")  val pendingCount: Int,
    @SerializedName("today_bookings") val todayBookings: List<BookingSummary>,
    @SerializedName("week_revenue")   val weekRevenue: Double,
    @SerializedName("upcoming_count") val upcomingCount: Int
)

// ── Bookings ─────────────────────────────────────────────
data class BookingSummary(
    val id: Int,
    @SerializedName("booking_ref") val ref: String,
    @SerializedName("c_name")      val clientName: String,
    @SerializedName("c_email")     val clientEmail: String?,
    @SerializedName("c_phone")     val clientPhone: String?,
    @SerializedName("s_name")      val serviceName: String,
    @SerializedName("variant_name") val variantName: String?,
    @SerializedName("booked_date") val date: String,
    @SerializedName("booked_time") val time: String,
    val status: String,
    @SerializedName("total_price")    val totalPrice: Double = 0.0,
    @SerializedName("deposit_amount") val depositAmount: Double = 0.0,
    @SerializedName("deposit_paid")   val depositPaid: Int = 0,
    @SerializedName("payment_method") val paymentMethod: String? = null,
    @SerializedName("receipt_url")    val receiptUrl: String? = null,
    // Multi-booking / calendar extras (populated by the calendar endpoint)
    @SerializedName("guest_name")     val guestName: String? = null,
    @SerializedName("cart_group_ref") val cartGroupRef: String? = null,
    @SerializedName("duration_mins")  val durationMins: Int? = null,
    @SerializedName("end_time")       val endTime: String? = null
)

data class BookingDetail(
    val id: Int,
    @SerializedName("booking_ref")           val ref: String,
    @SerializedName("c_name")               val clientName: String,
    @SerializedName("c_email")              val clientEmail: String?,
    @SerializedName("c_phone")              val clientPhone: String?,
    @SerializedName("s_name")               val serviceName: String,
    @SerializedName("variant_name")         val variantName: String?,
    @SerializedName("booked_date")          val date: String,
    @SerializedName("booked_time")          val time: String,
    val status: String,
    @SerializedName("total_price")          val totalPrice: Double,
    @SerializedName("deposit_amount")       val depositAmount: Double,
    @SerializedName("deposit_paid")         val depositPaid: Int,
    @SerializedName("remaining_balance")    val balance: Double,
    @SerializedName("payment_method")       val paymentMethod: String?,
    @SerializedName("payment_method_allowed") val paymentMethodAllowed: String?,
    @SerializedName("payment_token")        val paymentToken: String?,
    @SerializedName("receipt_url")          val receiptUrl: String?,
    @SerializedName("client_notes")         val clientNotes: String?,
    @SerializedName("admin_notes")          val adminNotes: String?,
    @SerializedName("created_at")           val createdAt: String,
    /** Admin-set duration override (minutes). null = use service default. */
    @SerializedName("duration_mins")        val durationMins: Int? = null,
    /** Service default duration (minutes) — always populated from JOIN. */
    @SerializedName("service_duration_mins") val serviceDurationMins: Int? = null,
    val addons: List<BookingAddon>,
    val payments: List<Payment>,
    @SerializedName("custom_style_name") val customStyleName: String? = null,
    @SerializedName("custom_style_desc") val customStyleDesc: String? = null,
    /** Who the appointment is for (family/group bookings). null = the account holder. */
    @SerializedName("guest_name")     val guestName: String? = null,
    /** Shared reference linking appointments booked together in one checkout. */
    @SerializedName("cart_group_ref") val cartGroupRef: String? = null
)

data class SetDurationRequest(@SerializedName("duration_mins") val durationMins: Int)
data class SetDurationResponse(
    val success: Boolean,
    @SerializedName("duration_mins") val durationMins: Int = 0,
    @SerializedName("end_time")      val endTime: String? = null
)

data class BookingAddon(val name: String, @SerializedName("price_charged") val price: Double)

data class Payment(
    val id: Int,
    val type: String,
    val amount: Double,
    val status: String,
    @SerializedName("stripe_id")    val stripeId: String?,
    @SerializedName("confirmed_by") val confirmedBy: String?,
    @SerializedName("created_at")   val createdAt: String
)

data class BookingsResponse(
    val bookings: List<BookingSummary>,
    val total: Int,
    val page: Int,
    @SerializedName("per_page") val perPage: Int
)

data class StatusRequest(val status: String)

// ── Calendar ─────────────────────────────────────────────
data class CalendarResponse(
    val bookings: List<BookingSummary>,
    val blocks: List<AvailabilityBlock>
)

data class AvailabilityBlock(
    @SerializedName("avail_date")   val date: String,
    @SerializedName("time_slot")    val timeSlot: String?,
    @SerializedName("block_reason") val reason: String?
)

data class BlockRequest(
    val date: String,
    @SerializedName("time_slot") val timeSlot: String? = null,
    val reason: String? = null
)

// ── Customers ────────────────────────────────────────────
data class Customer(
    val id: Int,
    val name: String,
    val email: String,
    val phone: String?,
    @SerializedName("loyalty_points") val loyaltyPoints: Int = 0,
    val tags: String? = null,
    @SerializedName("is_blocked")    val isBlocked: Int = 0,
    @SerializedName("block_reason")  val blockReason: String? = null,
    @SerializedName("hair_notes")    val hairNotes: String? = null,
    @SerializedName("created_at")    val createdAt: String,
    val bookings: List<CustomerBooking>? = null,
    val notes: List<CustomerNote>? = null,
    @SerializedName("loyalty_history") val loyaltyHistory: List<LoyaltyTransaction>? = null,
    val ltv: Double = 0.0,
    @SerializedName("completed_count") val completedCount: Int = 0
)

data class CustomerBooking(
    val id: Int,
    @SerializedName("booking_ref")  val ref: String,
    @SerializedName("booked_date")  val date: String,
    @SerializedName("booked_time")  val time: String,
    val status: String,
    @SerializedName("total_price")  val totalPrice: Double,
    @SerializedName("s_name")       val serviceName: String,
    @SerializedName("variant_name") val variantName: String?
)

data class CustomerNote(
    val id: Int,
    val note: String,
    @SerializedName("created_at") val createdAt: String
)

data class LoyaltyTransaction(
    val id: Int,
    val type: String,
    val points: Int,
    val description: String?,
    @SerializedName("created_at") val createdAt: String
)

data class CustomersResponse(val customers: List<Customer>, val total: Int)

// CRM action requests
data class NoteRequest(val note: String)
data class LoyaltyRequest(val points: Int, val description: String? = null)
data class BlockRequest2(
    @SerializedName("is_blocked")   val isBlocked: Boolean,
    @SerializedName("block_reason") val blockReason: String? = null
)

// ── Orders ───────────────────────────────────────────────
data class Order(
    val id: Int,
    @SerializedName("order_ref")     val ref: String,
    @SerializedName("c_name")        val clientName: String,
    @SerializedName("c_email")       val clientEmail: String?,
    val status: String,
    val total: Double,
    @SerializedName("delivery_type") val deliveryType: String?,
    @SerializedName("created_at")    val createdAt: String,
    val items: List<OrderItem>? = null
)

data class OrderItem(
    val name: String,
    val quantity: Int,
    @SerializedName("price_charged") val price: Double
)

data class OrdersResponse(val orders: List<Order>, val total: Int)

// ── Accounting ───────────────────────────────────────────
data class AccountingStats(
    @SerializedName("today_takings")   val todayTakings: Double,
    @SerializedName("month_revenue")   val monthRevenue: Double,
    @SerializedName("month_expenses")  val monthExpenses: Double,
    @SerializedName("month_draws")     val monthDraws: Double,
    @SerializedName("month_profit")    val monthProfit: Double,
    @SerializedName("recent_expenses") val recentExpenses: List<Expense>,
    @SerializedName("recent_draws")    val recentDraws: List<OwnerDraw>
)

data class Expense(
    val id: Int,
    @SerializedName("expense_date") val date: String,
    val description: String,
    val amount: Double,
    val category: String?,
    @SerializedName("created_at") val createdAt: String
)

data class OwnerDraw(
    val id: Int,
    @SerializedName("draw_date") val date: String,
    val amount: Double,
    val notes: String?,
    @SerializedName("created_at") val createdAt: String
)

data class ExpenseRequest(
    val amount: Double,
    val description: String,
    val category: String = "Business Expenses",
    val date: String,
    val notes: String? = null
)

data class DrawRequest(
    val amount: Double,
    val date: String,
    val notes: String? = null
)

// ── Booking actions ──────────────────────────────────────
data class RescheduleRequest(
    val date: String,
    val time: String,
    @SerializedName("duration_mins") val durationMins: Int? = null
)

// ── Services ─────────────────────────────────────────────
data class Service(
    val id: Int,
    val name: String,
    @SerializedName("price_from")    val priceFrom: Double,
    @SerializedName("duration_mins") val durationMins: Int,
    val variants: List<ServiceVariant> = emptyList(),
    val addons: List<ServiceAddon> = emptyList()
)

data class ServiceVariant(
    val id: Int,
    @SerializedName("variant_name") val variantName: String,
    val price: Double
)

data class ServiceAddon(
    val id: Int,
    val name: String,
    val price: Double
)

data class ServicesResponse(val services: List<Service>)

// ── Create booking ────────────────────────────────────────
data class CreateBookingRequest(
    @SerializedName("customer_mode")          val customerMode: String,
    @SerializedName("customer_id")            val customerId: Int? = null,
    @SerializedName("new_name")               val newName: String? = null,
    @SerializedName("new_email")              val newEmail: String? = null,
    @SerializedName("new_phone")              val newPhone: String? = null,
    @SerializedName("service_id")             val serviceId: Int,
    @SerializedName("variant_id")             val variantId: Int? = null,
    @SerializedName("addon_ids")              val addonIds: List<Int> = emptyList(),
    @SerializedName("booked_date")            val bookedDate: String,
    @SerializedName("booked_time")            val bookedTime: String,
    val status: String = "pending",
    @SerializedName("payment_method")         val paymentMethod: String = "bank_transfer",
    @SerializedName("payment_method_allowed") val paymentMethodAllowed: String = "both",
    @SerializedName("deposit_paid")           val depositPaid: Boolean = false,
    @SerializedName("client_notes")           val clientNotes: String? = null,
    @SerializedName("admin_notes")            val adminNotes: String? = null,
    @SerializedName("manual_price")           val manualPrice: Double? = null,
    @SerializedName("is_custom_style")        val isCustomStyle: Boolean = false,
    @SerializedName("custom_style_name")      val customStyleName: String? = null,
    @SerializedName("custom_style_desc")      val customStyleDesc: String? = null
)

data class CreateBookingResponse(
    val success: Boolean,
    val id: Int,
    @SerializedName("booking_ref")  val bookingRef: String,
    @SerializedName("payment_link") val paymentLink: String?
)

data class PaymentLinkResponse(
    val success: Boolean,
    @SerializedName("payment_link") val paymentLink: String?,
    val token: String?
)

data class NotesRequest(val notes: String)

// ── Stripe Terminal ───────────────────────────────────────
data class TerminalConnectionTokenResponse(
    val secret: String,
    @SerializedName("location_id") val locationId: String = ""
)

data class TerminalPaymentRequest(val type: String)  // "deposit" | "balance" | "full"

data class TerminalPaymentIntentResponse(
    @SerializedName("client_secret")     val clientSecret:    String,
    @SerializedName("payment_intent_id") val paymentIntentId: String,
    @SerializedName("amount_pence")      val amountPence:     Int,
    @SerializedName("amount_gbp")        val amountGbp:       Double,
    val type: String
)

data class TerminalConfirmRequest(
    @SerializedName("payment_intent_id") val paymentIntentId: String,
    val type: String,
    @SerializedName("amount_pence")      val amountPence:     Int
)

// ── Services (full admin view, including inactive) ────────
data class ServiceFull(
    val id: Int,
    val name: String,
    val description: String?,
    @SerializedName("price_from")    val priceFrom: Double,
    @SerializedName("duration_mins") val durationMins: Int,
    val category: String?,
    @SerializedName("is_active")     val isActive: Int,
    @SerializedName("display_order") val displayOrder: Int = 0,
    val variants: List<ServiceVariantFull> = emptyList(),
    val addons:   List<ServiceAddonFull>   = emptyList()
)

data class ServiceVariantFull(
    val id: Int,
    @SerializedName("service_id")    val serviceId: Int,
    @SerializedName("variant_name")  val variantName: String,
    val price: Double,
    @SerializedName("duration_mins") val durationMins: Int?
)

data class ServiceAddonFull(
    val id: Int,
    @SerializedName("service_id") val serviceId: Int? = null,
    val name: String,
    val price: Double,
    @SerializedName("is_active")  val isActive: Int = 1
)

data class ServicesFullResponse(
    val services: List<ServiceFull>
)

data class ServiceCreateRequest(
    val name: String,
    val description: String? = null,
    @SerializedName("price_from")    val priceFrom: Double,
    @SerializedName("duration_mins") val durationMins: Int,
    val category: String? = null
)

data class ServiceUpdateRequest(
    val name: String,
    val description: String? = null,
    @SerializedName("price_from")    val priceFrom: Double,
    @SerializedName("duration_mins") val durationMins: Int,
    val category: String? = null
)

data class VariantCreateRequest(
    @SerializedName("variant_name")  val variantName: String,
    val price: Double,
    @SerializedName("duration_mins") val durationMins: Int? = null
)

data class AddonCreateRequest(
    @SerializedName("service_id") val serviceId: Int,
    val name: String,
    val price: Double
)

data class AddonUpdateRequest(
    @SerializedName("addon_id") val addonId: Int,
    val name: String,
    val price: Double
)

data class ServiceMutateResponse(
    val success: Boolean,
    val id: Int? = null,
    @SerializedName("is_active") val isActive: Int? = null
)

// ── Maintenance ──────────────────────────────────────────
data class MaintenanceResponse(
    val success: Boolean,
    val completed: Int = 0,
    @SerializedName("addons_converted") val addonsConverted: Int = 0
)

// ── Reviews ─────────────────────────────────────────────
data class Review(
    val id: Int,
    @SerializedName("c_name")       val customerName: String,
    @SerializedName("s_name")       val serviceName: String?,
    @SerializedName("p_name")       val productName: String?,
    val rating: Int,
    @SerializedName("review_text")  val reviewText: String,
    val status: String,
    @SerializedName("submitted_at") val submittedAt: String
)

data class ReviewsResponse(val reviews: List<Review>, val total: Int)

// ── Custom Requests ──────────────────────────────────────
data class CustomRequest(
    val id: Int,
    val ref: String,
    val name: String,
    val email: String,
    val phone: String?,
    @SerializedName("style_desc")     val styleDesc: String,
    @SerializedName("hair_length")    val hairLength: String?,
    @SerializedName("preferred_date") val preferredDate: String?,
    @SerializedName("budget_range")   val budgetRange: String?,
    val status: String,
    @SerializedName("admin_reply")    val adminReply: String?,
    @SerializedName("created_at")     val createdAt: String
)

data class CustomRequestsResponse(val requests: List<CustomRequest>, val total: Int)

data class ReplyRequest(
    val reply: String,
    val status: String = "replied"
)

// ── Discounts ────────────────────────────────────────────
data class DiscountCode(
    val id: Int,
    val code: String,
    val type: String,
    val value: Double,
    @SerializedName("uses_limit")  val usesLimit: Int?,
    @SerializedName("uses_count")  val usesCount: Int,
    @SerializedName("expiry_date") val expiryDate: String?,
    @SerializedName("is_active")   val isActive: Int,
    @SerializedName("created_at")  val createdAt: String
)

data class DiscountsResponse(val discounts: List<DiscountCode>)

data class CreateDiscountRequest(
    val code: String,
    val type: String,
    val value: Double,
    @SerializedName("uses_limit")  val usesLimit: Int? = null,
    @SerializedName("expiry_date") val expiryDate: String? = null
)

// ── Live Chat ────────────────────────────────────────────
data class ChatSession(
    val id: Int,
    val uuid: String,
    @SerializedName("customer_name")  val customerName: String?,
    @SerializedName("customer_email") val customerEmail: String?,
    val status: String,
    @SerializedName("unread_admin")   val unreadAdmin: Int,
    @SerializedName("last_msg")       val lastMsg: String?,
    @SerializedName("last_msg_at")    val lastMsgAt: String?,
    @SerializedName("created_at")     val createdAt: String
)

data class ChatMessage(
    val id: Int,
    val sender: String,   // "customer" | "admin"
    val body: String,
    @SerializedName("created_at") val createdAt: String
)

data class ChatMessagesResponse(
    val session: ChatSession,
    val messages: List<ChatMessage>
)

data class ChatPollResponse(
    val messages: List<ChatMessage>,
    val closed: Boolean
)

data class ChatReplyRequest(val message: String)

data class RegisterTokenRequest(val token: String)

// ── Generic ──────────────────────────────────────────────
data class SuccessResponse(val success: Boolean)
data class ErrorResponse(val error: String)
