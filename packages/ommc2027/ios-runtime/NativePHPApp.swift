import SwiftUI
import Foundation
import PHP
import Bridge
import UIKit

var output = ""

private struct StartupMigrationArtifactPayload: Decodable {
    let runId: String
    let status: String
    let databasePath: String
    let connection: String
    let appliedMigrations: [String]
    let failedMigration: String?
    let exceptionClass: String?
    let exceptionMessage: String?
    let timestamp: String

    private enum CodingKeys: String, CodingKey {
        case runId = "run_id"
        case status
        case databasePath = "database_path"
        case connection
        case appliedMigrations = "applied_migrations"
        case failedMigration = "failed_migration"
        case exceptionClass = "exception_class"
        case exceptionMessage = "exception_message"
        case timestamp
    }
}

private struct StartupMigrationTracePayload: Codable {
    let runId: String
    let runtimeMode: String
    let checkpoint: String
    let timestamp: String
    let appPath: String?
    let storagePath: String?
    let databasePath: String?
    let command: String?
    let commandExitStatus: Int?
    let connection: String?
    let artifactPath: String?
    let phpEmbedInitResult: Int?
    let phpExecuteScriptReturned: Bool?
    let phpExecuteScriptHasReturnValue: Bool?
    let phpOutputExcerpt: String?
    let exceptionClass: String?
    let exceptionMessage: String?
    let history: [StartupMigrationTraceEntry]

    private enum CodingKeys: String, CodingKey {
        case runId = "run_id"
        case runtimeMode = "runtime_mode"
        case checkpoint
        case timestamp
        case appPath = "app_path"
        case storagePath = "storage_path"
        case databasePath = "database_path"
        case command
        case commandExitStatus = "command_exit_status"
        case connection
        case artifactPath = "artifact_path"
        case phpEmbedInitResult = "php_embed_init_result"
        case phpExecuteScriptReturned = "php_execute_script_returned"
        case phpExecuteScriptHasReturnValue = "php_execute_script_has_return_value"
        case phpOutputExcerpt = "php_output_excerpt"
        case exceptionClass = "exception_class"
        case exceptionMessage = "exception_message"
        case history
    }
}

private struct StartupMigrationTraceEntry: Codable {
    let checkpoint: String
    let timestamp: String
    let appPath: String?
    let storagePath: String?
    let databasePath: String?
    let command: String?
    let commandExitStatus: Int?
    let connection: String?
    let artifactPath: String?
    let phpEmbedInitResult: Int?
    let phpExecuteScriptReturned: Bool?
    let phpExecuteScriptHasReturnValue: Bool?
    let phpOutputExcerpt: String?
    let exceptionClass: String?
    let exceptionMessage: String?

    private enum CodingKeys: String, CodingKey {
        case checkpoint
        case timestamp
        case appPath = "app_path"
        case storagePath = "storage_path"
        case databasePath = "database_path"
        case command
        case commandExitStatus = "command_exit_status"
        case connection
        case artifactPath = "artifact_path"
        case phpEmbedInitResult = "php_embed_init_result"
        case phpExecuteScriptReturned = "php_execute_script_returned"
        case phpExecuteScriptHasReturnValue = "php_execute_script_has_return_value"
        case phpOutputExcerpt = "php_output_excerpt"
        case exceptionClass = "exception_class"
        case exceptionMessage = "exception_message"
    }
}

struct ClassicStartupInvocationResult {
    let output: String
    let phpEmbedInitResult: Int32
    let phpExecuteScriptReturned: Bool
    let phpExecuteScriptHasReturnValue: Bool
}

@_cdecl("pipe_php_output")
public func pipe_php_output(_ cString: UnsafePointer<CChar>?) {
    guard let cString = cString else { return }

    output += String(cString: cString)
}

@main
struct NativePHPApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate
    @StateObject private var appState = AppState.shared

    static var shared: NativePHPApp?

    @Environment(\.scenePhase) private var scenePhase

    init() {
        Self.shared = self

        DebugLogger.shared.log("📱 NativePHPApp.init() starting (minimal)")

        // Only register bridge functions in init - this is fast and doesn't block
        // All heavy initialization is deferred to after the splash view is visible
        DebugLogger.shared.log("📱 NativePHPApp.init() registering bridge functions")
        registerBridgeFunctions()

        DebugLogger.shared.log("📱 NativePHPApp.init() completed (minimal)")
    }

    /// Performs heavy initialization work after the splash view is visible.
    /// This runs on a background thread to avoid blocking the main thread.
    private func performDeferredInitialization() {
        NSLog("[NativePHP] Deferred initialization starting")

        // 1. Initialize PHP environment (env vars, php.ini, database)
        NSLog("[NativePHP] preparePhpEnvironment START")
        _ = preparePhpEnvironment()
        NSLog("[NativePHP] preparePhpEnvironment DONE")

        // 2. Ensure app is extracted from bundle if needed
        NSLog("[NativePHP] ensureAppExists START")
        let didExtract = AppUpdateManager.shared.ensureAppExists()
        NSLog("[NativePHP] ensureAppExists DONE (didExtract=\(didExtract), ready=\(AppUpdateManager.shared.appReady))")

        guard AppUpdateManager.shared.appReady,
              FileManager.default.fileExists(atPath: AppUpdateManager.shared.bootstrapFilePath()) else {
            let detail = AppUpdateManager.shared.lastExtractionError
                ?? "Missing \(AppUpdateManager.requiredBootstrapRelativePath) under Documents/app"
            NSLog("[NativePHP] FATAL startup: \(detail)")
            DebugLogger.shared.log("❌ Startup aborted — \(detail)")
            DispatchQueue.main.async {
                AppState.shared.markStartupFailed(detail)
            }
            return
        }

        // 3. Boot persistent PHP runtime (one-time Laravel boot) — unless classic mode
        let runtimeMode = Self.getRuntimeMode()
        NSLog("[NativePHP] Runtime mode: \(runtimeMode)")
        let startupMigrationRunId = UUID().uuidString
        let artifactURL = startupMigrationArtifactURL()
        let traceURL = startupMigrationTraceURL()

        let booted: Bool
        if runtimeMode == "classic" {
            NSLog("[NativePHP] Classic mode configured — skipping persistent runtime boot")
            booted = false
        } else {
            NSLog("[NativePHP] PersistentPHPRuntime.boot() START")
            booted = PersistentPHPRuntime.shared.boot()
            NSLog("[NativePHP] PersistentPHPRuntime.boot() DONE, booted=\(booted)")

            if booted {
                if didExtract {
                    NSLog("[NativePHP] artisan storage:link START")
                    _ = PersistentPHPRuntime.shared.artisan(command: "storage:link")
                    NSLog("[NativePHP] artisan storage:link DONE")
                }

                // Execute plugin post-boot callbacks
                NativePHPPluginRegistry.shared.executeOnAppReady()
            } else {
                NSLog("[NativePHP] persistent boot failed, falling back to classic mode")
            }
        }

        do {
            try deletePreviousStartupMigrationArtifact(at: artifactURL)
        } catch {
            let detail = "Startup migration artifact cleanup failed at \(artifactURL.path): \(error.localizedDescription)"
            NSLog("[NativePHP] \(detail)")
            DispatchQueue.main.async {
                AppState.shared.markStartupFailed(detail)
            }
            return
        }

        if !booted {
            do {
                try deletePreviousStartupMigrationTrace(at: traceURL)
            } catch {
                let detail = "Startup migration trace cleanup failed at \(traceURL.path): \(error.localizedDescription)"
                NSLog("[NativePHP] \(detail)")
                DispatchQueue.main.async {
                    AppState.shared.markStartupFailed(detail)
                }
                return
            }

            recordStartupMigrationTrace(
                at: traceURL,
                runId: startupMigrationRunId,
                runtimeMode: runtimeMode,
                checkpoint: "swift_before_classic_artisan",
                appPath: AppUpdateManager.shared.getAppPath(),
                storagePath: startupMigrationStorageRootURL().path,
                databasePath: startupDatabaseURL().path,
                command: "app:startup-migrate",
                phpOutputExcerpt: nil,
                exceptionClass: nil,
                exceptionMessage: nil,
                phpEmbedInitResult: nil,
                phpExecuteScriptReturned: nil,
                phpExecuteScriptHasReturnValue: nil,
                commandExitStatus: nil,
                artifactPath: artifactURL.path,
                connection: "sqlite"
            )
        }

        let migrationOutput: String
        if booted {
            migrationOutput = PersistentPHPRuntime.shared.artisan(command: "app:startup-migrate --run-id=\(startupMigrationRunId) --no-interaction")
        } else {
            let invocation = artisan(
                additionalArgs: ["app:startup-migrate", "--run-id=\(startupMigrationRunId)", "--no-interaction"],
                startupMigrationRunId: startupMigrationRunId,
                startupMigrationTraceURL: traceURL
            )

            migrationOutput = invocation.output

            recordStartupMigrationTrace(
                at: traceURL,
                runId: startupMigrationRunId,
                runtimeMode: runtimeMode,
                checkpoint: "swift_after_classic_artisan",
                appPath: AppUpdateManager.shared.getAppPath(),
                storagePath: startupMigrationStorageRootURL().path,
                databasePath: startupDatabaseURL().path,
                command: "app:startup-migrate",
                phpOutputExcerpt: invocation.output,
                exceptionClass: nil,
                exceptionMessage: nil,
                phpEmbedInitResult: Int(invocation.phpEmbedInitResult),
                phpExecuteScriptReturned: invocation.phpExecuteScriptReturned,
                phpExecuteScriptHasReturnValue: invocation.phpExecuteScriptHasReturnValue,
                commandExitStatus: nil,
                artifactPath: artifactURL.path,
                connection: "sqlite"
            )
        }

        let executionMode = booted ? "persistent" : "classic"
        NSLog("[NativePHP] Startup migration gate execution mode: \(executionMode)")

        guard let migrationArtifact = loadStartupMigrationArtifact(at: artifactURL) else {
            let detail = "Startup migration artifact missing after \(executionMode) invocation. Path: \(artifactURL.path). Output: \(migrationOutput)"
            NSLog("[NativePHP] \(detail)")
            DispatchQueue.main.async {
                AppState.shared.markStartupFailed(detail)
            }
            return
        }

        guard migrationArtifact.runId == startupMigrationRunId else {
            let detail = "Startup migration artifact run_id mismatch. Expected \(startupMigrationRunId), got \(migrationArtifact.runId). Path: \(artifactURL.path)"
            NSLog("[NativePHP] \(detail)")
            DispatchQueue.main.async {
                AppState.shared.markStartupFailed(detail)
            }
            return
        }

        NSLog("[NativePHP] Startup migration artifact status: \(migrationArtifact.status)")
        NSLog("[NativePHP] Startup migration artifact DB path: \(migrationArtifact.databasePath)")

        guard migrationArtifact.status == "current" || migrationArtifact.status == "migrated" || migrationArtifact.status == "failed" else {
            let detail = "Startup migration artifact had invalid status \(migrationArtifact.status). Path: \(artifactURL.path)"
            NSLog("[NativePHP] \(detail)")
            DispatchQueue.main.async {
                AppState.shared.markStartupFailed(detail)
            }
            return
        }

        guard migrationArtifact.status != "failed" else {
            let detail = startupMigrationFailureDetail(from: migrationArtifact, artifactURL: artifactURL, output: migrationOutput)
            NSLog("[NativePHP] Startup migration failed: \(detail)")
            DispatchQueue.main.async {
                AppState.shared.markStartupFailed(detail)
            }
            return
        }

        if !booted || didExtract {
            createStorageLink()
        }

        // 4. Now that PHP is booted, allow WebView to render
        DispatchQueue.main.async {
            DeepLinkRouter.shared.markPhpReady()
            AppState.shared.markReadyToLoad()
        }

        // 5. Execute plugin initialization callbacks (on main thread)
        DispatchQueue.main.async {
            NativePHPPluginRegistry.shared.executeOnAppLaunch()
        }

        // 6. Start hot reload server for development
        #if DEBUG
        HotReloadServer.shared.start()
        #endif

        // 7. Check for OTA updates (after everything is set up)
        NSLog("[NativePHP] checkForUpdates START")
        AppUpdateManager.shared.checkForUpdates()

        // 8. Defer queue worker boot — start AFTER critical path completes
        //    so it doesn't compete for CPU/memory during first page render
        if booted {
            NSLog("[NativePHP] PHPQueueWorker.start() (deferred)")
            PHPQueueWorker.shared.start()
        } else {
            NSLog("[NativePHP] Queue worker NOT started — persistent runtime boot failed")
        }

        NSLog("[NativePHP] Deferred initialization completed")
    }

    var body: some Scene {
        WindowGroup {
            ZStack {
                // Phase 2: Once deferred init is complete, render ContentView so WebView can load
                // It renders underneath the splash until WebView finishes loading
                if appState.isReadyToLoad {
                    ContentView()
                }

                // Splash overlays until WebView finishes loading (Phase 3),
                // or remains visible with an error if extraction failed.
                if !appState.isInitialized || appState.startupError != nil {
                    SplashView()
                        .transition(.opacity)
                        .onAppear {
                            guard appState.startupError == nil, !appState.isReadyToLoad else {
                                return
                            }
                            // Phase 1: Start deferred initialization on a background thread
                            // This runs AFTER the splash view is visible, avoiding watchdog timeout
                            DispatchQueue.global(qos: .userInitiated).async {
                                performDeferredInitialization()
                            }
                        }
                }
            }
            .animation(.easeInOut(duration: 0.3), value: appState.isInitialized)
            .onOpenURL { url in
                // Only handle if not already handled by AppDelegate during cold start
                if !DeepLinkRouter.shared.hasPendingURL() {
                    DeepLinkRouter.shared.handle(url: url)
                }
            }
            .onContinueUserActivity(NSUserActivityTypeBrowsingWeb) { activity in
                if activity.activityType == NSUserActivityTypeBrowsingWeb,
                let url = activity.webpageURL {
                    // Only handle if not already handled by AppDelegate during cold start
                    if !DeepLinkRouter.shared.hasPendingURL() {
                        DeepLinkRouter.shared.handle(url: url)   // Universal Links
                    }
                }
            }
        }
    }

    private func getAppSupportDir(dir: String) -> String {
        // Get the URL for the Library directory in the user domain
        let appSupportURL = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first

        // Append "Application Support" to the Library directory URL
        let destination = appSupportURL!.appendingPathComponent(dir)

        do {
            try FileManager.default.createDirectory(
                at: destination,
                withIntermediateDirectories: true,
                attributes: nil
            )
        } catch {
            // Handle the error
        }

        // If you need the path as a String
        return destination.path
    }

    /// Verify the extracted bootstrap exists; attempt one recovery re-extract if missing.
    private static func ensureBootstrapReady() -> String? {
        let bootstrapPath = AppUpdateManager.shared.bootstrapFilePath()

        if FileManager.default.fileExists(atPath: bootstrapPath) {
            return bootstrapPath
        }

        NSLog("[NativePHP] Bootstrap missing at \(bootstrapPath) — attempting one recovery re-extract")
        guard AppUpdateManager.shared.forceReextractFromBundle() else {
            return nil
        }

        guard FileManager.default.fileExists(atPath: bootstrapPath) else {
            return nil
        }

        return bootstrapPath
    }

    /// Read runtime_mode from bundle_meta.json. Returns "persistent" (default) or "classic".
    static func getRuntimeMode() -> String {
        guard let path = Bundle.main.path(forResource: "bundle_meta", ofType: "json"),
              let data = FileManager.default.contents(atPath: path),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let mode = json["runtime_mode"] as? String else {
            return "persistent"
        }
        return mode
    }

    /// Read the NATIVEPHP_START_URL from the .env file
    static func getStartURL() -> String {
        let appPath = AppUpdateManager.shared.getAppPath()
        let envPath = URL(fileURLWithPath: appPath).appendingPathComponent(".env")

        guard FileManager.default.fileExists(atPath: envPath.path),
              let envContent = try? String(contentsOf: envPath, encoding: .utf8) else {
            DebugLogger.shared.log("⚙️ No .env file found, using default start URL")
            return "/"
        }

        // Use regex to find NATIVEPHP_START_URL value
        let pattern = #"NATIVEPHP_START_URL\s*=\s*([^\r\n]+)"#
        if let regex = try? NSRegularExpression(pattern: pattern),
           let match = regex.firstMatch(in: envContent, range: NSRange(envContent.startIndex..., in: envContent)),
           let valueRange = Range(match.range(at: 1), in: envContent) {
            var value = String(envContent[valueRange])
                .trimmingCharacters(in: .whitespaces)
                .trimmingCharacters(in: CharacterSet(charactersIn: "\"'"))

            if !value.isEmpty {
                // Ensure path starts with /
                if !value.hasPrefix("/") {
                    value = "/" + value
                }
                DebugLogger.shared.log("⚙️ Found start URL in .env: \(value)")
                return value
            }
        }

        DebugLogger.shared.log("⚙️ No NATIVEPHP_START_URL found, using default: /")
        return "/"
    }

    private func createPhpIni() -> String {
        let caPath = Bundle.main.path(forResource: "cacert", ofType: "pem") ?? "Path not found"

        let phpIni = """
        curl.cainfo="\(caPath)"
        openssl.cafile="\(caPath)"
        """

        let supportDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let path = supportDir.appendingPathComponent("php.ini")

        do {
            try FileManager.default.createDirectory(at: supportDir, withIntermediateDirectories: true)

            try phpIni.write(to: path, atomically: true, encoding: .utf8)
        } catch {
            print("Couldn't create php.ini")
        }

        return supportDir.appendingPathComponent("php.ini").path
    }

    private func createDatabase() {
        let fileManager = FileManager.default

        let databaseFileURL = fileManager.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
            .appendingPathComponent("database/database.sqlite")

        if !fileManager.fileExists(atPath: databaseFileURL.path) {
            // Create an empty SQLite file
            fileManager.createFile(
                atPath: databaseFileURL.path,
                contents: nil,
                attributes: nil
            )
        }
    }

    private func migrateDatabase() {
        _ = artisan(additionalArgs: ["migrate", "--force"])
    }

    private func clearCaches() {
        _ = artisan(additionalArgs: ["view:clear"])
    }

    private func createStorageLink() {
        _ = artisan(additionalArgs: ["storage:link"])
    }

    private func startupMigrationArtifactURL() -> URL {
        return startupMigrationStorageRootURL()
            .appendingPathComponent("framework")
            .appendingPathComponent("nativephp")
            .appendingPathComponent("startup-migration-status.json")
    }

    private func startupMigrationTraceURL() -> URL {
        return startupMigrationStorageRootURL()
            .appendingPathComponent("framework")
            .appendingPathComponent("nativephp")
            .appendingPathComponent("startup-migration-trace.json")
    }

    private func startupMigrationStorageRootURL() -> URL {
        let supportDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!

        return supportDir
            .appendingPathComponent("storage")
    }

    private func startupDatabaseURL() -> URL {
        FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
            .appendingPathComponent("database/database.sqlite")
    }

    private func deletePreviousStartupMigrationArtifact(at artifactURL: URL) throws {
        let fileManager = FileManager.default

        guard fileManager.fileExists(atPath: artifactURL.path) else {
            return
        }

        try fileManager.removeItem(at: artifactURL)
    }

    private func deletePreviousStartupMigrationTrace(at traceURL: URL) throws {
        let fileManager = FileManager.default

        guard fileManager.fileExists(atPath: traceURL.path) else {
            return
        }

        try fileManager.removeItem(at: traceURL)
    }

    private func loadStartupMigrationArtifact(at artifactURL: URL) -> StartupMigrationArtifactPayload? {
        guard let data = try? Data(contentsOf: artifactURL) else {
            return nil
        }

        return try? JSONDecoder().decode(StartupMigrationArtifactPayload.self, from: data)
    }

    private func startupMigrationFailureDetail(
        from artifact: StartupMigrationArtifactPayload,
        artifactURL: URL,
        output: String
    ) -> String {
        let migrationSuffix = artifact.failedMigration.map { " Migration: \($0)." } ?? ""
        let classSuffix = artifact.exceptionClass.map { " Error: \($0)." } ?? ""
        let outputSuffix = output.isEmpty ? "" : " Output: \(output)"
        let errorMessage = artifact.exceptionMessage ?? "Unknown migration failure."

        return "Database migration failed for \(artifact.databasePath).\(migrationSuffix)\(classSuffix) \(errorMessage) Artifact: \(artifactURL.path).\(outputSuffix)"
    }

    private func loadStartupMigrationTrace(at traceURL: URL, runId: String) -> StartupMigrationTracePayload? {
        guard let data = try? Data(contentsOf: traceURL),
              let payload = try? JSONDecoder().decode(StartupMigrationTracePayload.self, from: data),
              payload.runId == runId else {
            return nil
        }

        return payload
    }

    private func recordStartupMigrationTrace(
        at traceURL: URL,
        runId: String,
        runtimeMode: String,
        checkpoint: String,
        appPath: String?,
        storagePath: String?,
        databasePath: String?,
        command: String?,
        phpOutputExcerpt: String?,
        exceptionClass: String?,
        exceptionMessage: String?,
        phpEmbedInitResult: Int?,
        phpExecuteScriptReturned: Bool?,
        phpExecuteScriptHasReturnValue: Bool?,
        commandExitStatus: Int?,
        artifactPath: String?,
        connection: String?
    ) {
        let timestamp = ISO8601DateFormatter().string(from: Date())
        let trimmedOutput = trimmedStartupTraceOutput(phpOutputExcerpt)

        let entry = StartupMigrationTraceEntry(
            checkpoint: checkpoint,
            timestamp: timestamp,
            appPath: appPath,
            storagePath: storagePath,
            databasePath: databasePath,
            command: command,
            commandExitStatus: commandExitStatus,
            connection: connection,
            artifactPath: artifactPath,
            phpEmbedInitResult: phpEmbedInitResult,
            phpExecuteScriptReturned: phpExecuteScriptReturned,
            phpExecuteScriptHasReturnValue: phpExecuteScriptHasReturnValue,
            phpOutputExcerpt: trimmedOutput,
            exceptionClass: exceptionClass,
            exceptionMessage: exceptionMessage
        )

        let history = (loadStartupMigrationTrace(at: traceURL, runId: runId)?.history ?? []) + [entry]
        let payload = StartupMigrationTracePayload(
            runId: runId,
            runtimeMode: runtimeMode,
            checkpoint: checkpoint,
            timestamp: timestamp,
            appPath: appPath,
            storagePath: storagePath,
            databasePath: databasePath,
            command: command,
            commandExitStatus: commandExitStatus,
            connection: connection,
            artifactPath: artifactPath,
            phpEmbedInitResult: phpEmbedInitResult,
            phpExecuteScriptReturned: phpExecuteScriptReturned,
            phpExecuteScriptHasReturnValue: phpExecuteScriptHasReturnValue,
            phpOutputExcerpt: trimmedOutput,
            exceptionClass: exceptionClass,
            exceptionMessage: exceptionMessage,
            history: history
        )

        do {
            try FileManager.default.createDirectory(at: traceURL.deletingLastPathComponent(), withIntermediateDirectories: true)
            let data = try JSONEncoder.startupTrace.encode(payload)
            try data.write(to: traceURL, options: .atomic)
        } catch {
            NSLog("[NativePHP] Failed to persist startup migration trace at \(traceURL.path): \(error.localizedDescription)")
        }
    }

    private func trimmedStartupTraceOutput(_ output: String?) -> String? {
        guard let output else {
            return nil
        }

        let trimmed = output.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else {
            return nil
        }

        return String(trimmed.prefix(500))
    }

    private func preparePhpEnvironment() -> String {
        let phpIniPath = createPhpIni()

        setenv("PHPRC", phpIniPath, 1)

        setupEnvironment()

        output = ""

        override_embed_module_output(pipe_php_output)

        createDatabase()

        return output
    }

    static func laravel(request: RequestData) -> String? {
        // Convert Swift strings to C strings
        let postDataC = strdup(request.data ?? "")
        let methodC = strdup(request.method)
        let uriC = strdup(request.uri)

        // Free the duplicated C strings
        defer {
            free(postDataC)
            free(methodC)
            free(uriC)
        }

        output = ""

        override_embed_module_output(pipe_php_output)

        var argv: [UnsafeMutablePointer<CChar>?] = [
            strdup("php")
        ]

        let argc = Int32(argv.count)

        guard let phpFilePath = Self.ensureBootstrapReady() else {
            NSLog("[NativePHP] Refusing to launch PHP — bootstrap unavailable")
            return """
            <!DOCTYPE html><html><body style="font-family:-apple-system;padding:2rem;">
            <h1>Unable to start app</h1>
            <p>The NativePHP runtime files could not be prepared. Please reinstall the app.</p>
            </body></html>
            """
        }

        print()
        print("=== FORWARDING REQUEST TO LARAVEL ===")
        print()

        var uri = request.uri
        if let query = request.query {
            uri += "?" + query
        }

        setenv("REMOTE_ADDR", "0.0.0.0", 1)
        setenv("REQUEST_URI", uri, 1)
        setenv("QUERY_STRING", request.query, 1);
        setenv("REQUEST_METHOD", request.method, 1)
        setenv("SCRIPT_FILENAME", phpFilePath, 1)
        setenv("PHP_SELF", "/native.php", 1)
        setenv("HTTP_HOST", "127.0.0.1", 1)
        setenv("ASSET_URL", "php://127.0.0.1/_assets/", 1)
        setenv("NATIVEPHP_RUNNING", "true", 1)
        setenv("APP_URL", "php://127.0.0.1", 1)

        var envKeys: [String] = []

        for (header, value) in request.headers {
            let formattedKey = "HTTP_" + header
                .replacingOccurrences(of: "-", with: "_")
                .uppercased()

            // Convert Swift strings to C strings
            guard let cKey = formattedKey.cString(using: .utf8),
                  let cValue = value.cString(using: .utf8) else {
                print("Failed to convert \(header) or its value to C string.")
                continue
            }

            // Set this as env so that it will get picked up in $_SERVER
            setenv(cKey, cValue, 1)
            envKeys.append(formattedKey)
        }

        // Equivalent to PHP_EMBED_START_BLOCK
        argv.withUnsafeMutableBufferPointer { bufferPtr in
            php_embed_init(argc, bufferPtr.baseAddress)

            initialize_php_with_request(postDataC, methodC, uriC)

            var fileHandle = zend_file_handle()
            zend_stream_init_filename(&fileHandle, phpFilePath)

            php_execute_script(&fileHandle)

            // Equivalent to PHP_EMBED_END_BLOCK
            php_embed_shutdown()

            // Clean up env variables for headers
            for key in envKeys {
                unsetenv(key)
            }
            envKeys.removeAll()
        }

        // Free argv strings
        argv.forEach { free($0) }

        print()
        print("=== LARAVEL FINISHED ===")
        print()

        return output
    }

    private func setupEnvironment() {
        let storageDir = getAppSupportDir(dir: "storage")
        let viewCacheDir = getAppSupportDir(dir: "storage/framework/views")
        let databaseDir = getAppSupportDir(dir: "database")

        // Ensure other directories exist
        _ = getAppSupportDir(dir: "storage/framework/cache")
        _ = getAppSupportDir(dir: "storage/framework/sessions")
        _ = getAppSupportDir(dir: "storage/logs")

        // Get temporary directory
        let tempDir = FileManager.default.temporaryDirectory.path

        setenv("NATIVEPHP_PLATFORM", "ios", 1)
        setenv("NATIVEPHP_TEMPDIR", tempDir, 1)
        setenv("LARAVEL_STORAGE_PATH", storageDir, 1)
        setenv("VIEW_COMPILED_PATH", viewCacheDir, 1)
        setenv("DB_DATABASE", "\(databaseDir)/database.sqlite", 1)

        // Set APP_KEY from secure storage (generates on first run)
        if let appKey = getOrGenerateAppKey() {
            setenv("APP_KEY", appKey, 1)
        }
    }

    /// Retrieves APP_KEY from Keychain or generates a new one on first launch
    private func getOrGenerateAppKey() -> String? {
        let service = Bundle.main.bundleIdentifier ?? "com.nativephp.app"
        let account = "APP_KEY"

        // Try to retrieve existing APP_KEY from Keychain
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: account,
            kSecAttrService as String: service,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]

        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)

        if status == errSecSuccess,
           let data = result as? Data,
           let existingKey = String(data: data, encoding: .utf8) {
            DebugLogger.shared.log("🔑 Retrieved existing APP_KEY from Keychain")
            return existingKey
        }

        // Generate new APP_KEY if not found
        DebugLogger.shared.log("🔑 Generating new APP_KEY for first launch")
        guard let newKey = generateAppKey() else {
            DebugLogger.shared.log("❌ Failed to generate APP_KEY")
            return nil
        }

        // Store in Keychain
        guard let keyData = newKey.data(using: .utf8) else {
            DebugLogger.shared.log("❌ Failed to encode APP_KEY")
            return nil
        }

        let addQuery: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: account,
            kSecAttrService as String: service,
            kSecValueData as String: keyData,
            kSecAttrAccessible as String: kSecAttrAccessibleWhenUnlockedThisDeviceOnly
        ]

        let addStatus = SecItemAdd(addQuery as CFDictionary, nil)

        if addStatus == errSecSuccess {
            DebugLogger.shared.log("✅ APP_KEY stored securely in Keychain")
            return newKey
        } else {
            DebugLogger.shared.log("❌ Failed to store APP_KEY in Keychain: \(addStatus)")
            return nil
        }
    }

    /// Generates a new Laravel-compatible APP_KEY (base64:...)
    private func generateAppKey() -> String? {
        var keyData = Data(count: 32)
        let result = keyData.withUnsafeMutableBytes {
            SecRandomCopyBytes(kSecRandomDefault, 32, $0.baseAddress!)
        }

        guard result == errSecSuccess else {
            return nil
        }

        // Laravel expects format: base64:...
        let base64Key = keyData.base64EncodedString()
        return "base64:\(base64Key)"
    }

    func artisan(
        additionalArgs: [String] = [],
        startupMigrationRunId: String? = nil,
        startupMigrationTraceURL: URL? = nil
    ) -> ClassicStartupInvocationResult {
        print("Running `php artisan \(additionalArgs.joined())`...")

        let appPath = AppUpdateManager.shared.getAppPath()
        let phpFilePath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/artisan.php"

        guard FileManager.default.fileExists(atPath: phpFilePath) else {
            NSLog("[NativePHP] Refusing artisan — missing \(phpFilePath)")
            return ClassicStartupInvocationResult(
                output: "",
                phpEmbedInitResult: -1,
                phpExecuteScriptReturned: false,
                phpExecuteScriptHasReturnValue: false
            )
        }

        output = ""

        override_embed_module_output(pipe_php_output)

        var argv: [UnsafeMutablePointer<CChar>?] = [
            strdup("php")
        ]

        setenv("PHP_SELF", "artisan.php", 1)
        setenv("APP_RUNNING_IN_CONSOLE", "true", 1)
        setenv("NATIVEPHP_RUNNING", "true", 1)
        setenv("NATIVEPHP_PLATFORM", "ios", 1)
        setenv("APP_URL", "php://127.0.0.1", 1)
        setenv("ASSET_URL", "php://127.0.0.1/_assets/", 1)

        if let startupMigrationRunId {
            setenv("NATIVEPHP_STARTUP_RUN_ID", startupMigrationRunId, 1)
            setenv("NATIVEPHP_STARTUP_RUNTIME_MODE", "classic", 1)
            setenv("NATIVEPHP_STARTUP_APP_PATH", appPath, 1)
            setenv("NATIVEPHP_STARTUP_STORAGE_PATH", startupMigrationStorageRootURL().path, 1)
            setenv("NATIVEPHP_STARTUP_DATABASE_PATH", startupDatabaseURL().path, 1)

            if let startupMigrationTraceURL {
                setenv("NATIVEPHP_STARTUP_TRACE_PATH", startupMigrationTraceURL.path, 1)
            }
        }

        let additionalCArgs = additionalArgs.map { strdup($0) }
        argv.append(contentsOf: additionalCArgs)

        let argc = Int32(argv.count)
        var phpEmbedInitResult: Int32 = -1
        var phpExecuteScriptReturned = false

        argv.withUnsafeMutableBufferPointer { bufferPtr in
            phpEmbedInitResult = php_embed_init(argc, bufferPtr.baseAddress)

            guard phpEmbedInitResult == 0 else {
                return
            }

            var fileHandle = zend_file_handle()
            zend_stream_init_filename(&fileHandle, phpFilePath)

            php_execute_script(&fileHandle)
            phpExecuteScriptReturned = true

            php_embed_shutdown()
        }

        argv.forEach { free($0) }

        return ClassicStartupInvocationResult(
            output: output,
            phpEmbedInitResult: phpEmbedInitResult,
            phpExecuteScriptReturned: phpExecuteScriptReturned,
            phpExecuteScriptHasReturnValue: false
        )
    }
}

private extension JSONEncoder {
    static var startupTrace: JSONEncoder {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .withoutEscapingSlashes]
        return encoder
    }
}
