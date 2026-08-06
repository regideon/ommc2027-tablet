import SwiftUI

/// A view that replicates the launch screen appearance.
/// This allows the app to appear launched while heavy initialization continues in the background.
struct SplashView: View {
    @ObservedObject private var appState = AppState.shared

    var body: some View {
        GeometryReader { geometry in
            ZStack {
                // Background color matching launch screen (white)
                Color.white
                    .ignoresSafeArea()

                // LaunchImage scaled to fill (matching LaunchScreen.storyboard scaleAspectFill)
                Image("LaunchImage")
                    .resizable()
                    .aspectRatio(contentMode: .fill)
                    .frame(width: geometry.size.width, height: geometry.size.height)
                    .clipped()
                    .ignoresSafeArea()

                if let startupError = appState.startupError {
                    VStack(spacing: 16) {
                        Text("Unable to start app")
                            .font(.title2.weight(.bold))
                            .foregroundColor(.primary)
                        Text(startupError)
                            .font(.body)
                            .foregroundColor(.secondary)
                            .multilineTextAlignment(.center)
                            .padding(.horizontal, 24)
                        Text("Delete and reinstall the app if this continues. Details were written to the native log.")
                            .font(.footnote)
                            .foregroundColor(.secondary)
                            .multilineTextAlignment(.center)
                            .padding(.horizontal, 24)
                    }
                    .padding(24)
                    .background(.ultraThinMaterial, in: RoundedRectangle(cornerRadius: 16))
                    .padding(24)
                }
            }
        }
        .ignoresSafeArea()
    }
}

#Preview {
    SplashView()
}
