import { getApps, initializeApp } from 'firebase/app'
import {
    browserPopupRedirectResolver,
    FacebookAuthProvider,
    GithubAuthProvider,
    GoogleAuthProvider,
    inMemoryPersistence,
    initializeAuth,
    OAuthProvider,
    signInWithPopup,
    signOut,
    TwitterAuthProvider,
} from 'firebase/auth'

// The names match `SocialProvider::getSdkClass()` on the server.
const providerClasses = {
    FacebookAuthProvider,
    GithubAuthProvider,
    GoogleAuthProvider,
    OAuthProvider,
    TwitterAuthProvider,
}

// A named Firebase app, so that the plugin never changes a default app of the host page.
const appName = 'filament-firebase-auth'

let firebaseAuth = null

// The Laravel session is the only session. The SDK keeps the Firebase user in memory only.
function getFirebaseAuth(config) {
    if (firebaseAuth) {
        return firebaseAuth
    }

    const app = getApps().find((existingApp) => existingApp.name === appName) ?? initializeApp(config, appName)

    firebaseAuth = initializeAuth(app, {
        persistence: inMemoryPersistence,
        popupRedirectResolver: browserPopupRedirectResolver,
    })

    return firebaseAuth
}

function makeProvider({ id, sdkClass, scopes, parameters }) {
    const ProviderClass = providerClasses[sdkClass]
    const provider = ProviderClass === OAuthProvider ? new OAuthProvider(id) : new ProviderClass()

    for (const scope of scopes) {
        provider.addScope(scope)
    }

    if (Object.keys(parameters).length > 0) {
        provider.setCustomParameters(parameters)
    }

    return provider
}

// Filament prints the token in `<meta name="csrf-token">` on every panel page.
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

function popupErrorMessage(error, messages) {
    switch (error?.code) {
        case 'auth/popup-closed-by-user':
        case 'auth/cancelled-popup-request':
            return messages.popupClosed
        case 'auth/popup-blocked':
            return messages.popupBlocked
        case 'auth/account-exists-with-different-credential':
            return messages.accountExists
        default:
            return messages.generic
    }
}

export default function firebaseSignIn({ config, providers, endpoint, messages }) {
    return {
        busy: false,

        error: null,

        // Safari and mobile browsers block a popup that opens after other async work in the click.
        // So Firebase Auth starts when the buttons appear, and `signInWithPopup()` is the first await of the click.
        init() {
            try {
                getFirebaseAuth(config)
            } catch {
                // An invalid web config fails again on the click, and the click shows the error.
            }
        },

        async signIn(providerId) {
            const options = providers.find((provider) => provider.id === providerId)

            if (!options || this.busy) {
                return
            }

            this.busy = true
            this.error = null

            let auth = null

            try {
                auth = getFirebaseAuth(config)

                const { user } = await signInWithPopup(auth, makeProvider(options))
                const credential = await user.getIdToken()

                // The token goes in the request body only, never in a URL.
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ credential }),
                })

                const body = await response.json().catch(() => ({}))

                if (response.ok && typeof body.redirect === 'string') {
                    // The buttons stay disabled while the browser leaves the page.
                    window.location.assign(body.redirect)

                    return
                }

                if (response.status === 419) {
                    // The CSRF token of the page is no longer valid, for example after a long idle time.
                    this.error = messages.sessionExpired
                } else {
                    this.error = response.status === 422 && typeof body.message === 'string' ? body.message : messages.generic
                }
            } catch (error) {
                this.error = popupErrorMessage(error, messages)
            } finally {
                if (auth) {
                    await signOut(auth).catch(() => {})
                }
            }

            this.busy = false
        },
    }
}

function registerAlpineData() {
    window.Alpine.data('firebaseSignIn', firebaseSignIn)
}

// `x-load-js` loads this bundle after Alpine has started.
if (window.Alpine) {
    registerAlpineData()
} else {
    document.addEventListener('alpine:init', registerAlpineData)
}
