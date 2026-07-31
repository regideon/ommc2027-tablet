package com.nativephp.mobile.bridge

import android.util.Log
import org.json.JSONObject
import java.util.concurrent.locks.ReentrantReadWriteLock
import kotlin.concurrent.read
import kotlin.concurrent.write

// MARK: - Bridge Error Types

sealed class BridgeError(val code: String, override val message: String) : Exception(message) {
    class FunctionNotFound(functionName: String) :
        BridgeError("FUNCTION_NOT_FOUND", "Function '$functionName' not found in bridge registry")

    class InvalidParameters(details: String) :
        BridgeError("INVALID_PARAMETERS", "Invalid parameters: $details")

    class ExecutionFailed(details: String) :
        BridgeError("EXECUTION_FAILED", "Function execution failed: $details")

    class PermissionDenied(details: String) :
        BridgeError("PERMISSION_DENIED", "Permission denied: $details")

    class PermissionRequired(details: String) :
        BridgeError("PERMISSION_REQUIRED", "Permission required: $details")

    class UnknownError(details: String) :
        BridgeError("UNKNOWN_ERROR", "Unknown error: $details")
}

// MARK: - Bridge Function Protocol

/**
 * Interface that all bridge functions must implement
 */
interface BridgeFunction {
    /**
     * Execute the function with the given parameters
     * @param parameters Map of parameters passed from PHP
     * @return Map of data to return to PHP
     * @throws BridgeError if execution fails
     */
    @Throws(BridgeError::class)
    fun execute(parameters: Map<String, Any>): Map<String, Any>
}

// MARK: - Bridge Function Registry

/**
 * Thread-safe registry for bridge functions
 */
class BridgeFunctionRegistry private constructor() {
    private val functions = mutableMapOf<String, BridgeFunction>()
    private val lock = ReentrantReadWriteLock()

    companion object {
        val shared = BridgeFunctionRegistry()
        private const val TAG = "BridgeFunctionRegistry"
    }

    /**
     * Register a function with the bridge
     * @param name The fully qualified name (e.g., "Location.Get", "Camera.TakePhoto")
     * @param function The function implementation conforming to BridgeFunction
     */
    fun register(name: String, function: BridgeFunction) {
        lock.write {
            functions[name] = function
        }
    }

    /**
     * Check if a function exists in the registry
     * @param name The fully qualified function name
     * @return True if the function exists, false otherwise
     */
    fun exists(name: String): Boolean = lock.read {
        functions.containsKey(name)
    }

    /**
     * Get a function from the registry
     * @param name The fully qualified function name
     * @return The function implementation, or null if not found
     */
    fun get(name: String): BridgeFunction? = lock.read {
        functions[name]
    }

    /**
     * Get all registered function names (useful for debugging)
     */
    fun getAllFunctionNames(): List<String> = lock.read {
        functions.keys.sorted()
    }
}

// MARK: - Bridge Response Builder

object BridgeResponse {
    /**
     * Build a success response
     * Returns function data directly without wrapping
     */
    fun success(data: Map<String, Any> = emptyMap()): Map<String, Any> {
        return data
    }

    /**
     * Build an error response
     */
    fun error(code: String, message: String, data: Map<String, Any> = emptyMap()): Map<String, Any> {
        return mapOf(
            "status" to "error",
            "code" to code,
            "message" to message,
            "data" to data
        )
    }

    /**
     * Build an error response from a BridgeError
     */
    fun error(error: BridgeError, data: Map<String, Any> = emptyMap()): Map<String, Any> {
        return error(error.code, error.message, data)
    }

    /**
     * Convert response map to JSON string
     */
    fun toJSON(response: Map<String, Any>): String? {
        return try {
            JSONObject(response).toString()
        } catch (e: Exception) {
            Log.e("BridgeResponse", "❌ Failed to serialize bridge response: ${e.message}")
            null
        }
    }
}

// MARK: - JNI Bridge Functions

/**
 * Check if a native function exists in the bridge registry
 * Called from PHP via JNI
 * @param functionName The fully qualified function name (e.g., "Location.Get")
 * @return 1 if function exists, 0 if it doesn't
 */
@Suppress("unused") // Called from JNI
fun nativePHPCan(functionName: String): Int {
    val exists = BridgeFunctionRegistry.shared.exists(functionName)
    return if (exists) 1 else 0
}

/**
 * Call a native function through the bridge router
 * Called from PHP via JNI
 * @param functionName The fully qualified function name (e.g., "Location.Get")
 * @param parametersJSON JSON string containing function parameters
 * @return JSON string with result: {"status": "success"|"error", "data": {...}, "code": "...", "message": "..."}
 *         Returns null if function doesn't exist (check with nativePHPCan first)
 */
@Suppress("unused") // Called from JNI
fun nativePHPCall(functionName: String, parametersJSON: String?): String? {
    // Check if function exists - return null if not
    if (!BridgeFunctionRegistry.shared.exists(functionName)) {
        Log.e("BridgeRouter", "❌ Function '$functionName' not found")
        return null
    }

    // Parse parameters JSON
    val parameters = mutableMapOf<String, Any>()
    if (!parametersJSON.isNullOrEmpty()) {
        try {
            // Handle empty array [] as empty object (no parameters)
            if (parametersJSON.trim() == "[]") {
                // Empty array, treat as no parameters
                // parameters map stays empty
            } else {
                val jsonObject = JSONObject(parametersJSON)
                val keys = jsonObject.keys()
                while (keys.hasNext()) {
                    val key = keys.next()
                    parameters[key] = jsonObject.get(key)
                }
            }
        } catch (e: Exception) {
            val response = BridgeResponse.error(
                code = "INVALID_JSON",
                message = "Failed to parse parameters JSON: ${e.message}"
            )
            return BridgeResponse.toJSON(response)
        }
    }

    // Get the function
    val function = BridgeFunctionRegistry.shared.get(functionName)
    if (function == null) {
        Log.e("BridgeRouter", "❌ Function '$functionName' disappeared between checks")
        return null
    }

    // Execute the function with error handling
    return try {
        val result = function.execute(parameters)
        val response = BridgeResponse.success(data = result)

        val jsonString = BridgeResponse.toJSON(response)
        if (jsonString != null) {
            jsonString
        } else {
            val errorResponse = BridgeResponse.error(
                code = "SERIALIZATION_ERROR",
                message = "Failed to serialize response to JSON"
            )
            BridgeResponse.toJSON(errorResponse)
        }
    } catch (error: BridgeError) {
        Log.w("BridgeRouter", "⚠️ Function '$functionName' failed: ${error.message}")
        val response = BridgeResponse.error(error)
        BridgeResponse.toJSON(response)
    } catch (error: Exception) {
        Log.e("BridgeRouter", "❌ Function '$functionName' unexpected error: ${error.message}")
        val response = BridgeResponse.error(
            code = "UNKNOWN_ERROR",
            message = "Unexpected error: ${error.message}"
        )
        BridgeResponse.toJSON(response)
    }
}