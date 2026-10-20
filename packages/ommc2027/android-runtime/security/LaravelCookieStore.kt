package com.nativephp.mobile.security

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

object LaravelCookieStore {
    private lateinit var prefs: SharedPreferences
    private val cookies = mutableMapOf<String, String>()
    private var initialized = false
    private const val TAG = "LaravelCookies"
    private const val PREF_NAME = "laravel_cookies"
    private const val REMEMBER_COOKIE_PREFIX = "remember_"

    @Synchronized
    fun init(context: Context) {
        // Activity recreation must keep the in-memory session cookies. Only a
        // new process reloads the durable store.
        if (initialized) {
            return
        }

        prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)

        val editor = prefs.edit()

        prefs.all.forEach { (key, value) ->
            if (value is String && key.startsWith(REMEMBER_COOKIE_PREFIX)) {
                cookies[key] = value
            } else {
                editor.remove(key)
            }
        }

        editor.apply()
        initialized = true
        logAll()
    }

    @Synchronized
    fun storeFromSetCookieHeader(header: String) {
        val parts = header.split(";")
        val nameValue = parts.firstOrNull()?.split("=", limit = 2) ?: return

        if (nameValue.size != 2) {
            return
        }

        val name = nameValue[0].trim()
        val value = nameValue[1].trim()

        if (name.isEmpty()) {
            return
        }

        if (value.isEmpty() || isExpired(parts.drop(1))) {
            cookies.remove(name)
            prefs.edit().remove(name).apply()

            return
        }

        cookies[name] = value

        if (name.startsWith(REMEMBER_COOKIE_PREFIX)) {
            prefs.edit().putString(name, value).apply()
        } else {
            // Session and CSRF cookies must not survive a process restart.
            // They stay in the in-memory map for the current run only.
            prefs.edit().remove(name).apply()
        }

        Log.d(TAG, "🍪 Stored cookie: $name")
    }

    @Synchronized
    fun asCookieHeader(): String {
        val cookieString = cookies.entries.joinToString("; ") { "${it.key}=${it.value}" }
        Log.d(TAG, "📤 Cookie header names: ${cookies.keys.joinToString(",")}")

        return cookieString
    }

    @Synchronized
    fun has(name: String): Boolean = cookies.containsKey(name)

    @Synchronized
    fun get(name: String): String? = cookies[name]

    @Synchronized
    fun clear() {
        Log.d(TAG, "🧹 Clearing cookies")
        cookies.clear()
        prefs.edit().clear().apply()
    }

    @Synchronized
    fun logAll() {
        Log.d(TAG, "📦 Stored cookies:")
        cookies.keys.forEach { name ->
            Log.d(TAG, "   → $name")
        }
    }

    private fun isExpired(attributes: List<String>): Boolean {
        for (attribute in attributes) {
            val trimmed = attribute.trim()
            val lower = trimmed.lowercase(Locale.US)

            if (lower.startsWith("max-age=")) {
                val seconds = lower.substringAfter("max-age=").trim().toLongOrNull() ?: continue

                if (seconds <= 0L) {
                    return true
                }
            }

            if (lower.startsWith("expires=")) {
                val raw = trimmed.substringAfter("=").trim()
                val expires = SimpleDateFormat("EEE, dd MMM yyyy HH:mm:ss z", Locale.US).parse(raw)

                if (expires != null && expires.before(Date())) {
                    return true
                }
            }
        }

        return false
    }
}
