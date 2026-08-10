import Foundation
import CoreLocation

// MARK: - Geolocation Function Namespace

/// Functions related to device location
/// Namespace: "Geolocation.*"
enum GeolocationFunctions {

    // MARK: - Geolocation.GetCurrentPosition

    /// Get the current device location
    /// Parameters:
    ///   - id: (optional) string - Optional ID to correlate this location request
    ///   - event: (optional) string - Custom event class to fire (defaults to "Native\Mobile\Events\Geolocation\LocationReceived")
    ///   - fineAccuracy: (optional) bool - Request high accuracy GPS fix (defaults to false)
    /// Returns:
    ///   - (empty map - results are returned via events)
    /// Events:
    ///   - Fires "Native\Mobile\Events\Geolocation\LocationReceived" (or custom event) when location is obtained
    class GetCurrentPosition: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String
            let event = parameters["event"] as? String ?? "Native\\Mobile\\Events\\Geolocation\\LocationReceived"
            let fineAccuracy = parameters["fineAccuracy"] as? Bool ?? false

            print("📍 Getting current position (id=\(id ?? "nil"), event=\(event), fineAccuracy=\(fineAccuracy))")

            DispatchQueue.main.async {
                LocationHelper.shared.requestLocation(fineAccuracy: fineAccuracy, id: id, event: event)
            }

            return [:]
        }
    }

    // MARK: - Geolocation.CheckPermissions

    /// Check the current location permission status
    /// Parameters:
    ///   - id: (optional) string - Optional ID to correlate this request
    ///   - event: (optional) string - Custom event class to fire (defaults to "Native\Mobile\Events\Geolocation\PermissionStatusReceived")
    /// Events:
    ///   - Fires "Native\Mobile\Events\Geolocation\PermissionStatusReceived" (or custom event) with the current status
    class CheckPermissions: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String
            let event = parameters["event"] as? String ?? "Native\\Mobile\\Events\\Geolocation\\PermissionStatusReceived"

            print("📍 Checking location permission status")

            DispatchQueue.main.async {
                let status = LocationHelper.shared.status
                var payload: [String: Any] = [
                    "granted": status == .authorizedWhenInUse || status == .authorizedAlways,
                    "denied": status == .denied || status == .restricted,
                    "notDetermined": status == .notDetermined,
                ]
                if let id = id {
                    payload["id"] = id
                }
                LaravelBridge.shared.send?(event, payload)
            }

            return [:]
        }
    }

    // MARK: - Geolocation.RequestPermissions

    /// Request location permission from the user
    /// Parameters:
    ///   - id: (optional) string - Optional ID to correlate this request
    ///   - event: (optional) string - Custom event class to fire (defaults to "Native\Mobile\Events\Geolocation\PermissionRequestResult")
    /// Events:
    ///   - Fires "Native\Mobile\Events\Geolocation\PermissionRequestResult" (or custom event) when the user responds
    class RequestPermissions: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String
            let event = parameters["event"] as? String ?? "Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult"

            print("📍 Requesting location permission (id=\(id ?? "nil"), event=\(event))")

            DispatchQueue.main.async {
                LocationHelper.shared.requestPermissions(id: id, event: event)
            }

            return [:]
        }
    }
}

// MARK: - Location Helper

/// Shared CLLocationManager wrapper that requests permission and
/// one-shot location fixes, firing the standard NativePHP geolocation events.
final class LocationHelper: NSObject, CLLocationManagerDelegate {
    static let shared = LocationHelper()

    private var manager: CLLocationManager
    private var pendingId: String?
    private var pendingEvent: String?
    private var fineAccuracy = false
    private var waitForLocationAfterPermission = false

    var status: CLAuthorizationStatus {
        manager.authorizationStatus
    }

    private override init() {
        manager = CLLocationManager()
        super.init()
        manager.delegate = self
    }

    /// Request location permission from the user and fire the result event.
    func requestPermissions(id: String?, event: String) {
        pendingId = id
        pendingEvent = event
        waitForLocationAfterPermission = false

        switch status {
        case .notDetermined:
            manager.requestWhenInUseAuthorization()
        default:
            firePermissionResult(status: status, id: id, event: event)
            clearPending()
        }
    }

    /// Request a one-shot location fix. If permission is not yet determined,
    /// request it first and fetch the location once granted.
    func requestLocation(fineAccuracy: Bool, id: String?, event: String) {
        self.fineAccuracy = fineAccuracy
        pendingId = id
        pendingEvent = event

        manager.desiredAccuracy = fineAccuracy ? kCLLocationAccuracyBest : kCLLocationAccuracyHundredMeters

        switch status {
        case .authorizedWhenInUse, .authorizedAlways:
            manager.requestLocation()
        case .notDetermined:
            waitForLocationAfterPermission = true
            manager.requestWhenInUseAuthorization()
        default:
            fireLocationError(id: id, event: event, error: "Location permission not granted")
            clearPending()
        }
    }

    // MARK: CLLocationManagerDelegate

    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        let status = manager.authorizationStatus

        guard status != .notDetermined else {
            return
        }

        let granted = status == .authorizedWhenInUse || status == .authorizedAlways

        if waitForLocationAfterPermission {
            waitForLocationAfterPermission = false
            if granted {
                manager.requestLocation()
            } else {
                fireLocationError(
                    id: pendingId,
                    event: pendingEvent ?? "Native\\Mobile\\Events\\Geolocation\\LocationReceived",
                    error: "Location permission denied"
                )
                clearPending()
            }
        } else if pendingEvent != nil {
            firePermissionResult(
                status: status,
                id: pendingId,
                event: pendingEvent ?? "Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult"
            )
            clearPending()
        }
    }

    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        let event = pendingEvent ?? "Native\\Mobile\\Events\\Geolocation\\LocationReceived"
        let id = pendingId

        guard let location = locations.last else {
            fireLocationError(id: id, event: event, error: "No location data available")
            clearPending()
            return
        }

        var payload: [String: Any] = [
            "success": true,
            "latitude": location.coordinate.latitude,
            "longitude": location.coordinate.longitude,
            "accuracy": location.horizontalAccuracy,
            "timestamp": Int(location.timestamp.timeIntervalSince1970 * 1000),
            "provider": "gps",
        ]
        if let id = id {
            payload["id"] = id
        }
        LaravelBridge.shared.send?(event, payload)
        clearPending()
    }

    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        fireLocationError(
            id: pendingId,
            event: pendingEvent ?? "Native\\Mobile\\Events\\Geolocation\\LocationReceived",
            error: error.localizedDescription
        )
        clearPending()
    }

    // MARK: Helpers

    private func firePermissionResult(status: CLAuthorizationStatus, id: String?, event: String) {
        var payload: [String: Any] = [
            "granted": status == .authorizedWhenInUse || status == .authorizedAlways,
            "permanentlyDenied": status == .denied,
        ]
        if let id = id {
            payload["id"] = id
        }
        LaravelBridge.shared.send?(event, payload)
    }

    private func fireLocationError(id: String?, event: String, error: String) {
        var payload: [String: Any] = ["success": false, "error": error]
        if let id = id {
            payload["id"] = id
        }
        LaravelBridge.shared.send?(event, payload)
    }

    private func clearPending() {
        pendingId = nil
        pendingEvent = nil
        fineAccuracy = false
        waitForLocationAfterPermission = false
    }
}
