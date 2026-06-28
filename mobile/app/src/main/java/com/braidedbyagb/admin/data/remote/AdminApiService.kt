package com.braidedbyagb.admin.data.remote

import com.braidedbyagb.admin.data.model.*
import retrofit2.Response
import retrofit2.http.*

// All calls go directly to api/admin.php with ?endpoint=X&action=Y&id=Z
// This bypasses .htaccess rewriting entirely — works on any host config.

interface AdminApiService {

    // ── Auth ──────────────────────────────────────────────────
    @POST("api/admin.php?endpoint=auth")
    suspend fun login(@Body req: AuthRequest): Response<AuthResponse>

    // ── Dashboard ─────────────────────────────────────────────
    @GET("api/admin.php?endpoint=dashboard")
    suspend fun getDashboard(): Response<DashboardStats>

    // ── Bookings list ─────────────────────────────────────────
    @GET("api/admin.php?endpoint=bookings")
    suspend fun getBookings(
        @Query("status") status: String? = null,
        @Query("q")      q:      String? = null,
        @Query("page")   page:   Int     = 1
    ): Response<BookingsResponse>

    // ── Booking detail ────────────────────────────────────────
    @GET("api/admin.php?endpoint=bookings")
    suspend fun getBooking(@Query("id") id: Int): Response<BookingDetail>

    // ── Update status ─────────────────────────────────────────
    @POST("api/admin.php?endpoint=bookings&action=status")
    suspend fun updateBookingStatus(
        @Query("id") id: Int,
        @Body req: StatusRequest
    ): Response<SuccessResponse>

    // ── Confirm deposit ───────────────────────────────────────
    @POST("api/admin.php?endpoint=bookings&action=deposit")
    suspend fun confirmDeposit(@Query("id") id: Int): Response<SuccessResponse>

    // ── Reschedule ────────────────────────────────────────────
    @POST("api/admin.php?endpoint=bookings&action=reschedule")
    suspend fun rescheduleBooking(
        @Query("id") id: Int,
        @Body req: RescheduleRequest
    ): Response<SuccessResponse>

    // ── Delete booking ────────────────────────────────────────
    @DELETE("api/admin.php?endpoint=bookings")
    suspend fun deleteBooking(@Query("id") id: Int): Response<SuccessResponse>

    // ── Calendar ──────────────────────────────────────────────
    @GET("api/admin.php?endpoint=calendar")
    suspend fun getCalendar(
        @Query("year")  year:  Int,
        @Query("month") month: Int
    ): Response<CalendarResponse>

    @POST("api/admin.php?endpoint=calendar&action=block")
    suspend fun blockSlot(@Body req: BlockRequest): Response<SuccessResponse>

    @HTTP(method = "DELETE", path = "api/admin.php?endpoint=calendar&action=block", hasBody = true)
    suspend fun unblockSlot(@Body req: BlockRequest): Response<SuccessResponse>

    // ── Customers ─────────────────────────────────────────────
    @GET("api/admin.php?endpoint=customers")
    suspend fun getCustomers(
        @Query("q")    q:    String? = null,
        @Query("page") page: Int     = 1
    ): Response<CustomersResponse>

    @GET("api/admin.php?endpoint=customers")
    suspend fun getCustomer(@Query("id") id: Int): Response<Customer>

    // ── CRM actions ───────────────────────────────────────────
    @POST("api/admin.php?endpoint=customers&action=note")
    suspend fun addCustomerNote(
        @Query("id") id: Int,
        @Body req: NoteRequest
    ): Response<SuccessResponse>

    @POST("api/admin.php?endpoint=customers&action=loyalty")
    suspend fun adjustLoyalty(
        @Query("id") id: Int,
        @Body req: LoyaltyRequest
    ): Response<SuccessResponse>

    @POST("api/admin.php?endpoint=customers&action=block")
    suspend fun blockCustomer(
        @Query("id") id: Int,
        @Body req: BlockRequest2
    ): Response<SuccessResponse>

    @DELETE("api/admin.php?endpoint=customers")
    suspend fun deleteCustomer(@Query("id") id: Int): Response<SuccessResponse>

    // ── Accounting ────────────────────────────────────────────
    @GET("api/admin.php?endpoint=accounting")
    suspend fun getAccounting(): Response<AccountingStats>

    @POST("api/admin.php?endpoint=accounting&action=expense")
    suspend fun logExpense(@Body req: ExpenseRequest): Response<SuccessResponse>

    @POST("api/admin.php?endpoint=accounting&action=draw")
    suspend fun logDraw(@Body req: DrawRequest): Response<SuccessResponse>

    // ── Orders ────────────────────────────────────────────────
    @GET("api/admin.php?endpoint=orders")
    suspend fun getOrders(
        @Query("status") status: String? = null,
        @Query("page")   page:   Int     = 1
    ): Response<OrdersResponse>

    @GET("api/admin.php?endpoint=orders")
    suspend fun getOrder(@Query("id") id: Int): Response<Order>

    @POST("api/admin.php?endpoint=orders&action=status")
    suspend fun updateOrderStatus(
        @Query("id") id: Int,
        @Body req: StatusRequest
    ): Response<SuccessResponse>

    // ── Settings ──────────────────────────────────────────────
    @GET("api/admin.php?endpoint=settings")
    suspend fun getSettings(): Response<Map<String, String>>

    @POST("api/admin.php?endpoint=settings")
    suspend fun updateSetting(@Body body: Map<String, String>): Response<SuccessResponse>

    // ── Services (booking creation — active only) ─────────────
    @GET("api/admin.php?endpoint=services")
    suspend fun getServices(): Response<ServicesResponse>

    // ── Services admin CRUD ────────────────────────────────────
    @GET("api/admin.php?endpoint=services")
    suspend fun getServicesAdmin(): Response<ServicesFullResponse>

    @POST("api/admin.php?endpoint=services")
    suspend fun createService(@Body req: ServiceCreateRequest): Response<ServiceMutateResponse>

    @POST("api/admin.php?endpoint=services&action=update")
    suspend fun updateService(
        @Query("id") id: Int,
        @Body req: ServiceUpdateRequest
    ): Response<ServiceMutateResponse>

    @POST("api/admin.php?endpoint=services&action=toggle")
    suspend fun toggleService(@Query("id") id: Int): Response<ServiceMutateResponse>

    @POST("api/admin.php?endpoint=services&action=add_variant")
    suspend fun addVariant(
        @Query("id") serviceId: Int,
        @Body req: VariantCreateRequest
    ): Response<ServiceMutateResponse>

    @HTTP(method = "DELETE", path = "api/admin.php?endpoint=services&action=del_variant", hasBody = false)
    suspend fun deleteVariant(@Query("variant_id") variantId: Int): Response<SuccessResponse>

    @POST("api/admin.php?endpoint=services&action=add_addon")
    suspend fun addAddon(
        @Body req: AddonCreateRequest
    ): Response<ServiceMutateResponse>

    @POST("api/admin.php?endpoint=services&action=update_addon")
    suspend fun updateAddon(
        @Body req: AddonUpdateRequest
    ): Response<SuccessResponse>

    @HTTP(method = "DELETE", path = "api/admin.php?endpoint=services&action=del_addon", hasBody = false)
    suspend fun deleteAddon(@Query("addon_id") addonId: Int): Response<SuccessResponse>

    // ── Create booking ────────────────────────────────────────
    @POST("api/admin.php?endpoint=bookings")
    suspend fun createBooking(@Body req: CreateBookingRequest): Response<CreateBookingResponse>

    // ── Stripe Terminal ───────────────────────────────────────
    @POST("api/admin.php?endpoint=terminal_connection_token")
    suspend fun getTerminalConnectionToken(): Response<TerminalConnectionTokenResponse>

    @POST("api/admin.php?endpoint=bookings&action=terminal_payment")
    suspend fun createTerminalPaymentIntent(
        @Query("id") bookingId: Int,
        @Body req: TerminalPaymentRequest
    ): Response<TerminalPaymentIntentResponse>

    @POST("api/admin.php?endpoint=bookings&action=terminal_confirm")
    suspend fun confirmTerminalPayment(
        @Query("id") bookingId: Int,
        @Body req: TerminalConfirmRequest
    ): Response<SuccessResponse>

    // ── Update admin notes ────────────────────────────────────
    @POST("api/admin.php?endpoint=bookings&action=notes")
    suspend fun updateBookingNotes(
        @Query("id") id: Int,
        @Body req: NotesRequest
    ): Response<SuccessResponse>

    // ── Payment link management ───────────────────────────────
    @POST("api/admin.php?endpoint=bookings&action=payment_link")
    suspend fun regeneratePaymentLink(@Query("id") id: Int): Response<PaymentLinkResponse>

    @HTTP(method = "DELETE", path = "api/admin.php?endpoint=bookings&action=payment_link", hasBody = false)
    suspend fun revokePaymentLink(@Query("id") id: Int): Response<SuccessResponse>

    // ── Booking duration ──────────────────────────────────────
    @POST("api/admin.php?endpoint=bookings&action=set_duration")
    suspend fun setBookingDuration(
        @Query("id") bookingId: Int,
        @Body req: SetDurationRequest
    ): Response<SetDurationResponse>

    // ── Maintenance ───────────────────────────────────────────
    @POST("api/admin.php?endpoint=maintenance")
    suspend fun runMaintenance(
        @Body body: Map<String, String>
    ): Response<MaintenanceResponse>

    // ── Reviews ───────────────────────────────────────────────
    @GET("api/admin.php?endpoint=reviews")
    suspend fun getReviews(
        @Query("status") status: String? = null
    ): Response<ReviewsResponse>

    @POST("api/admin.php?endpoint=reviews&action=approve")
    suspend fun approveReview(@Query("id") id: Int): Response<SuccessResponse>

    @POST("api/admin.php?endpoint=reviews&action=reject")
    suspend fun rejectReview(@Query("id") id: Int): Response<SuccessResponse>

    // ── Custom Requests ───────────────────────────────────────
    @GET("api/admin.php?endpoint=custom_requests")
    suspend fun getCustomRequests(
        @Query("status") status: String? = null
    ): Response<CustomRequestsResponse>

    @POST("api/admin.php?endpoint=custom_requests&action=mark_viewed")
    suspend fun markRequestViewed(@Query("id") id: Int): Response<SuccessResponse>

    @POST("api/admin.php?endpoint=custom_requests&action=reply")
    suspend fun replyToRequest(
        @Query("id") id: Int,
        @Body req: ReplyRequest
    ): Response<SuccessResponse>

    // ── Discounts ─────────────────────────────────────────────
    @GET("api/admin.php?endpoint=discounts")
    suspend fun getDiscounts(): Response<DiscountsResponse>

    @POST("api/admin.php?endpoint=discounts&action=create")
    suspend fun createDiscount(@Body req: CreateDiscountRequest): Response<ServiceMutateResponse>

    @POST("api/admin.php?endpoint=discounts&action=toggle")
    suspend fun toggleDiscount(@Query("id") id: Int): Response<ServiceMutateResponse>

    // ── Live Chat ─────────────────────────────────────────────
    @POST("api/livechat.php?action=register_token")
    suspend fun registerFcmToken(@Body req: RegisterTokenRequest): Response<SuccessResponse>

    @GET("api/livechat.php?action=sessions")
    suspend fun getChatSessions(
        @Query("status") status: String = "active"
    ): Response<List<ChatSession>>

    @GET("api/livechat.php?action=messages")
    suspend fun getChatMessages(@Query("id") id: Int): Response<ChatMessagesResponse>

    @GET("api/livechat.php?action=poll")
    suspend fun pollChat(
        @Query("uuid")  uuid:  String,
        @Query("after") after: Int
    ): Response<ChatPollResponse>

    @POST("api/livechat.php?action=reply")
    suspend fun sendChatReply(
        @Query("id") id: Int,
        @Body req: ChatReplyRequest
    ): Response<SuccessResponse>

    @POST("api/livechat.php?action=close")
    suspend fun closeChatSession(@Query("id") id: Int): Response<SuccessResponse>
}
