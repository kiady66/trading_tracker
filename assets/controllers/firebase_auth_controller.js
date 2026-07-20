import { Controller } from '@hotwired/stimulus';
import { initializeApp, getApps, getApp } from 'firebase/app';
import {
    getAuth,
    GoogleAuthProvider,
    signInWithPopup,
    signInWithRedirect,
    getRedirectResult,
    setPersistence,
    signOut,
    inMemoryPersistence,
    browserSessionPersistence,
} from 'firebase/auth';

/**
 * Connexion via Firebase.
 *
 * Le token obtenu ne sert qu'une fois, à ouvrir la session Symfony : tout le
 * reste de l'application fonctionne ensuite sur la session classique. On coupe
 * donc la session Firebase juste après, pour ne pas maintenir deux sources de
 * vérité qui pourraient diverger.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        apiKey: String,
        authDomain: String,
        projectId: String,
        endpoint: String,
    };

    static targets = ['button', 'error'];

    connect() {
        this.auth = getAuth(this.firebaseApp());

        // Retour d'un login par redirection : le flux reprend ici puisque la
        // page a été rechargée entre-temps.
        this.resumeRedirect();
    }

    async resumeRedirect() {
        let result;

        try {
            result = await getRedirectResult(this.auth);
        } catch (error) {
            this.showError(this.messageFor(error));
            return;
        }

        if (result) {
            this.setLoading(true);
            await this.exchangeToken(result.user);
        }
    }

    async signIn(event) {
        event.preventDefault();
        this.clearError();
        this.setLoading(true);

        const provider = new GoogleAuthProvider();

        try {
            // inMemoryPersistence suffit ici : la session Firebase n'a pas à
            // survivre à l'échange de token.
            await setPersistence(this.auth, inMemoryPersistence);
            const result = await signInWithPopup(this.auth, provider);
            await this.exchangeToken(result.user);
        } catch (error) {
            if (this.shouldFallBackToRedirect(error)) {
                await this.redirectFallback(provider);
                return;
            }

            this.setLoading(false);

            // L'utilisateur a simplement fermé la popup ou cliqué deux fois :
            // ce n'est pas une erreur à lui signaler.
            if (!this.isSilent(error)) {
                this.showError(this.messageFor(error));
            }
        }
    }

    async redirectFallback(provider) {
        try {
            // inMemoryPersistence est incompatible avec la redirection : le
            // résultat doit survivre au rechargement de la page.
            await setPersistence(this.auth, browserSessionPersistence);
            await signInWithRedirect(this.auth, provider);
        } catch (error) {
            this.setLoading(false);
            this.showError(this.messageFor(error));
        }
    }

    async exchangeToken(user) {
        try {
            const idToken = await user.getIdToken();
            await signOut(this.auth);

            const response = await fetch(this.endpointValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idToken }),
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                this.setLoading(false);
                this.showError(payload.error || 'La connexion a échoué.');
                return;
            }

            // Chargement complet volontaire plutôt qu'une navigation Turbo :
            // la session vient de changer, toute la page doit refléter le nouvel état.
            window.location.assign(payload.redirectTo);
        } catch (error) {
            this.setLoading(false);
            this.showError(this.messageFor(error));
        }
    }

    firebaseApp() {
        if (getApps().length > 0) {
            return getApp();
        }

        return initializeApp({
            apiKey: this.apiKeyValue,
            authDomain: this.authDomainValue,
            projectId: this.projectIdValue,
        });
    }

    shouldFallBackToRedirect(error) {
        return [
            'auth/popup-blocked',
            'auth/operation-not-supported-in-this-environment',
        ].includes(error.code);
    }

    isSilent(error) {
        return ['auth/popup-closed-by-user', 'auth/cancelled-popup-request'].includes(error.code);
    }

    messageFor(error) {
        if (error.code === 'auth/network-request-failed') {
            return 'Connexion au service impossible. Vérifiez votre réseau et réessayez.';
        }

        if (error.code === 'auth/unauthorized-domain') {
            return "Ce domaine n'est pas autorisé dans la console Firebase.";
        }

        return 'La connexion a échoué. Réessayez.';
    }

    setLoading(loading) {
        if (!this.hasButtonTarget) {
            return;
        }

        this.buttonTarget.disabled = loading;
        this.buttonTarget.classList.toggle('disabled', loading);
    }

    showError(message) {
        if (!this.hasErrorTarget) {
            return;
        }

        this.errorTarget.textContent = message;
        this.errorTarget.classList.remove('d-none');
    }

    clearError() {
        if (!this.hasErrorTarget) {
            return;
        }

        this.errorTarget.textContent = '';
        this.errorTarget.classList.add('d-none');
    }
}
