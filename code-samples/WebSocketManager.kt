package net.reacollege.reacheckin.data.websocket

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import net.reacollege.reacheckin.BuildConfig
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener

/**
 * WebSocketMessage represents the structure of messages sent/received via WebSocket.
 * This data class defines the protocol for real-time communication between clients.
 *
 * @property messageType The type of message (e.g., "checkbox-changed", "checkout-all", "ping", "pong")
 * @property checkboxId Optional user ID for checkbox-related messages (null for other message types)
 */
@Serializable
data class WebSocketMessage(
    val messageType: String,
    val checkboxId: Int? = null
)

/**
 * WebSocketManager handles real-time bidirectional communication between multiple app instances.
 * This enables live updates when users check in/out from different devices or browsers.
 *
 * Unlike HTTP requests that are one-time request-response cycles, WebSockets maintain
 * an open connection for instant two-way messaging. This class implements automatic
 * reconnection with exponential backoff and keepalive monitoring to ensure resilient connectivity.
 *
 * Key responsibilities:
 * - Establishing and maintaining WebSocket connection
 * - Processing incoming real-time updates (checkbox changes, checkout-all events)
 * - Broadcasting updates to other connected clients
 * - Automatic reconnection with exponential backoff strategy
 * - Keepalive monitoring to detect dead connections
 * - Providing reactive streams (Flows) for UI components to observe
 */
class WebSocketManager {
    // Dependencies
    /** HTTP client for establishing WebSocket connections */
    private val client = OkHttpClient()

    /** JSON serializer for converting messages to/from JSON format */
    private val json = Json { ignoreUnknownKeys = true }

    /** Coroutine scope for handling async operations on background thread with crash isolation */
    private val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    // Connection state properties
    /** The active WebSocket connection (null when disconnected) */
    private var webSocket: WebSocket? = null

    /** Coroutine job for scheduled reconnection attempts (can be cancelled if manual reconnection occurs) */
    private var reconnectJob: Job? = null
    /** Number of reconnection attempts made since last successful connection */
    private var reconnectAttempts = 0
    /** Maximum allowed reconnection attempts before giving up */
    private val maxReconnectAttempts = 4

    // Keepalive properties
    /** Timestamp (in milliseconds) when the last ping message was received from the server */
    private var lastPingTime = 0L
    /** Coroutine job that monitors for ping timeout and triggers reconnection if no ping received */
    private var pingTimeoutJob: Job? = null
    /** Maximum time (in milliseconds) allowed between ping messages before assuming connection is dead */
    private val pingTimeout = 30000L // 30 seconds

    // Reactive streams for broadcasting events to subscribers
    // These are like event buses that multiple components can listen to
    /**
     * Private mutable flow for checkbox update events.
     * SharedFlow allows multiple subscribers to receive the same events.
     */
    private val _checkboxUpdates = MutableSharedFlow<Int>()
    /** Public read-only stream of checkbox updates (user ID of updated user) */
    val checkboxUpdates: SharedFlow<Int> = _checkboxUpdates

    /** Private mutable flow for checkout-all events */
    private val _checkoutAllUpdates = MutableSharedFlow<Unit>()
    /** Public read-only stream of checkout-all events (Unit = like void, just signals the event occurred) */
    val checkoutAllUpdates: SharedFlow<Unit> = _checkoutAllUpdates

    /** Private mutable state for connection status */
    private val _isConnected = MutableStateFlow(false)
    /** Public read-only connection status that UI can observe to show connectivity indicators */
    val isConnected: StateFlow<Boolean> = _isConnected

    // The WebSocketListener
    /**
     * WebSocketListener handles WebSocket lifecycle events and incoming messages.
     * This is like an event handler that responds to network communication events.
     */
    private val webSocketListener = object : WebSocketListener() {
        /**
         * Called when WebSocket connection is successfully established.
         * Resets reconnection state and starts keepalive monitoring.
         *
         * @param webSocket The WebSocket connection
         * @param response The HTTP response from the server
         */
        override fun onOpen(webSocket: WebSocket, response: Response) {
//            println("WebSocket Connected")
            _isConnected.value = true
            reconnectAttempts = 0 // Reset reconnection counter on successful connection
            lastPingTime = System.currentTimeMillis()
            resetPingTimeout() // Start monitoring for keepalive pings
        }

        /**
         * Called when a message is received from the server.
         * Processes incoming real-time updates and distributes them to subscribers.
         *
         * Handles three message types:
         * - "ping": Server keepalive - respond with pong
         * - "checkbox-changed": User check-in/out update - broadcast to subscribers
         * - "checkout-all": All users checked out - broadcast to subscribers
         *
         * @param webSocket The WebSocket connection
         * @param text The JSON message received from server
         */
        override fun onMessage(webSocket: WebSocket, text: String) {
//            println("Received webSocket message: $text")
            try {
                // Parse the JSON message into our data structure
                val message = json.decodeFromString<WebSocketMessage>(text)
//                println("Parsed message: messageType=${message.messageType}, checkboxId=${message.checkboxId}")

                // Handle different types of messages
                when (message.messageType) {
                    "ping" -> {
                        // Respond to keepalive ping
                        lastPingTime = System.currentTimeMillis()
                        sendPong()
                        resetPingTimeout()
                    }

                    "checkbox-changed" -> {
                        // Someone checked in/out - notify all subscribers
                        message.checkboxId?.let { userId ->
//                            println("Emitting checkbox update for userId: $userId")
                            scope.launch {
                                _checkboxUpdates.emit(userId)
                            }
                        }
                    }

                    "checkout-all" -> {
                        // Everyone was checked out at once - notify subscribers
                        scope.launch {
                            _checkoutAllUpdates.emit(Unit)
                        }
                    }
                }
            } catch (e: Exception) {
                println("Failed to parse WebSocket message: ${e.message}")
            }
        }

        /**
         * Called when WebSocket connection fails or encounters an error.
         * Marks connection as disconnected and triggers automatic reconnection logic.
         *
         * @param webSocket The WebSocket connection that failed
         * @param t The error/exception that occurred
         * @param response The HTTP response (if any) associated with the failure
         */
        override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
            println("WebSocket Error: ${t.message}")
            _isConnected.value = false
            scheduleReconnect()
        }

        /**
         * Called when WebSocket connection is closed (either normally or due to error).
         * If closure code indicates an error, triggers automatic reconnection.
         *
         * @param webSocket The WebSocket connection that closed
         * @param code Closure code (1000 = normal, others indicate errors)
         * @param reason Human-readable reason for closure
         */
        override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
            println("WebSocket Closed: $reason")
            _isConnected.value = false
            if (code != 1000) { // 1000 is a normal closure
                scheduleReconnect()
            }
        }
    }

    // Public API methods
    /**
     * Establishes a WebSocket connection to the server.
     * This starts the real-time communication channel. Safe to call multiple times.
     */
    fun connect() {
//        println("Attempting to connect to WebSocket")
        val request = Request.Builder()
            .url(BuildConfig.WS_BASE_URL) // WebSocket server URL
            .build()
        webSocket = client.newWebSocket(request, webSocketListener)
    }

    /**
     * Manually reconnects the WebSocket connection.
     * Resets reconnection attempt counter and forces immediate reconnection.
     * Used for user-initiated reconnection or recovering from connection issues.
     */
    fun manualReconnect() {
        reconnectAttempts = 0 // Reset attempt counter
        disconnect()
        connect()
    }

    /**
     * Cleanly closes the WebSocket connection.
     * Cancels all pending reconnection attempts and keepalive monitoring.
     * Should be called when the app is closing or no longer needs real-time updates.
     */
    fun disconnect() {
        reconnectJob?.cancel() // Stop any pending reconnection attempts
        pingTimeoutJob?.cancel() // Stop ping monitoring
        webSocket?.close(1000, "Manual disconnect") // 1000 = normal closure
        webSocket = null
    }

    /**
     * Sends a checkbox update notification to all other connected clients.
     * When a user checks in/out, this broadcasts the change so other app
     * instances can update their UI in real-time.
     *
     * @param userId ID of the user whose check status changed
     */
    fun sendCheckboxUpdate(userId: Int) {
        val message = WebSocketMessage("checkbox-changed", userId)
        val jsonString = json.encodeToString(WebSocketMessage.serializer(), message)
        webSocket?.send(jsonString)
    }

    // Private helper methods
    /**
     * Schedules automatic reconnection with exponential backoff strategy.
     * Implements resilient connectivity by retrying with increasing delays:
     * - Attempt 1: 5 seconds
     * - Attempt 2: 10 seconds
     * - Attempt 3: 30 seconds
     * - Attempt 4: 60 seconds
     *
     * Gives up after 4 failed attempts to avoid infinite reconnection loops.
     */
    private fun scheduleReconnect() {
//        println("Websocket disconnected. Starting reconnection logic...")
        if (reconnectAttempts >= maxReconnectAttempts) {
            println("Max reconnection attempts ($maxReconnectAttempts) reached. Giving up.")
            return
        }

        // Exponential backoff: wait longer between each reconnection attempt
        val delay = when (reconnectAttempts) {
            0 -> 5000L // 5s
            1 -> 10000L // 10s
            2 -> 30000L // 30s
            3 -> 60000L // 1m
            else -> 60000L
        }

//        println("Scheduling reconnection attempt ${reconnectAttempts + 1}/$maxReconnectAttempts")

        // Cancel any previous reconnection attempt
        reconnectJob?.cancel()
        reconnectJob = CoroutineScope(Dispatchers.IO).launch {
//            println("Waiting ${delay / 1000}s before reconnection attempt...")
            delay(delay)
            reconnectAttempts++
//            println("Attempting WebSocket Reconnection (attempt $reconnectAttempts/$maxReconnectAttempts")
            connect()
        }
    }

    /**
     * Sends a pong response to the server's ping keepalive message.
     * This confirms the connection is still alive from the client side.
     */
    private fun sendPong() {
        val message = WebSocketMessage("pong")
        val jsonString = json.encodeToString(WebSocketMessage.serializer(), message)
        webSocket?.send(jsonString)
    }

    /**
     * Resets the ping timeout monitoring timer.
     * If no ping is received within 30 seconds, assumes the connection died
     * and triggers reconnection. This prevents zombie connections where the
     * socket appears connected but is actually dead.
     */
    private fun resetPingTimeout() {
        pingTimeoutJob?.cancel()
        pingTimeoutJob = scope.launch {
            delay(pingTimeout)
//                println("Ping timeout - no keepalive received in ${pingTimeout / 1000}s")
            _isConnected.value = false
            webSocket?.close(1000, "Ping timeout")
            scheduleReconnect()
        }
    }
}
