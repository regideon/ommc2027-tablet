import Foundation

// AUTO-GENERATED FILE - DO NOT EDIT
// Generated from installed NativePHP plugins

func registerPluginBridgeFunctions() {
    let registry = BridgeFunctionRegistry.shared

    // Initialize plugins


    // Register bridge functions
    // Plugin: ommc2027/camera
    registry.register("Camera.GetPhoto", function: CameraFunctions.GetPhoto())

    // Plugin: ommc2027/camera
    registry.register("Camera.RecordVideo", function: CameraFunctions.RecordVideo())

    // Plugin: ommc2027/camera
    registry.register("Camera.PickMedia", function: CameraFunctions.PickMedia())
}