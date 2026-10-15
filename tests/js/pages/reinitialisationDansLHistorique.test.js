import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest'
import { createApp, h } from 'vue'

/*
 * La page de réinitialisation du mot de passe, ouverte par un lien qui vaut,
 * avec le vrai client d'Inertia : elle montre l'adresse du compte, et Inertia
 * la range dans l'historique du navigateur, que le bouton Retour relit sans
 * requête. Le serveur la sert donc avec `encryptHistory`, comme une page de
 * compte (`HandleInertiaRequests::traiterCommeUnePageDeCompte()`,
 * `OuvertureDuLienDeReinitialisationTest`) : Inertia chiffre l'entrée avec la
 * clé de l'onglet (`crypto.subtle`, que le navigateur n'offre qu'en HTTPS et
 * sur la boucle locale). Ce fichier vérifie que le client le fait bien de
 * cette page : l'entrée est un `ArrayBuffer` où l'adresse ne se lit pas, et
 * rien d'autre que le navigateur garde ne la recopie. Reste le champ de la
 * page ouverte, où elle se lit en clair, en lecture seule ; la page initiale
 * du document, que l'historique chiffré ne couvre pas, est vidée au montage
 * (pageInitialeDeLaReinitialisation.test.js).
 *
 * L'entrée se reconnaît comme Inertia la reconnaît avant de la déchiffrer,
 * par `instanceof ArrayBuffer`, jamais par sa marque (`.ai/rules/tests-js.md`).
 * Le client d'Inertia se monte une fois pour le fichier, qui n'a qu'un cas.
 */

// Le layout invité marque l'abonnement push ; ce n'est pas le sujet ici.
vi.mock('@/composables/useAbonnementPush', () => ({
    detacherLAppareil: () => Promise.resolve(),
    marquerLAbonnementARetransmettre: () => {},
}))
vi.mock('@/Utils/SyncService', () => ({
    default: { compte: null, enAttente: () => 0, clearFailedRequests: () => {} },
}))

const ADRESSE = 'titulaire.historique@example.org'
const PARTIE_LOCALE = 'titulaire.historique'

/** La page telle que le serveur la rend pour un lien qui vaut, en HTTPS. */
const PAGE_DU_LIEN = {
    component: 'Auth/ResetPassword',
    url: '/reset-password/jeton-du-courriel?adresse=valeur-chiffree',
    version: 'v1',
    props: {
        errors: {},
        auth: { user: null },
        flash: { success: null, error: null },
        token: 'jeton-du-courriel',
        adresse: 'valeur-chiffree',
        compte: ADRESSE,
    },
    encryptHistory: true,
}

let application

/** Tout ce qu'un stockage du navigateur garde, en texte. */
const contenuDe = (stockage) =>
    Object.keys(stockage)
        .map((cle) => `${cle}=${stockage.getItem(cle)}`)
        .join('\n')

beforeAll(async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    window.sessionStorage.clear()
    window.localStorage.clear()
    window.history.replaceState(null, '', PAGE_DU_LIEN.url)
    globalThis.route = (nom) => `/${nom}`

    // Le document tel que le serveur l'écrit : la page initiale, lue par Inertia.
    const initiale = document.createElement('script')
    initiale.dataset.page = 'app'
    initiale.type = 'application/json'
    initiale.textContent = JSON.stringify(PAGE_DU_LIEN)
    document.body.replaceChildren(initiale, Object.assign(document.createElement('div'), { id: 'app' }))

    const inertia = await import('@inertiajs/vue3')
    const ResetPassword = (await import('@/Pages/Auth/ResetPassword.vue')).default

    await inertia.createInertiaApp({
        resolve: () => ResetPassword,
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.directive('press', {})
            application.config.globalProperties.route = globalThis.route
            application.use(plugin).mount(el)
        },
    })

    // L'entrée, quelle qu'elle soit : chiffrée ou non, c'est au test de le dire.
    await vi.waitUntil(() => window.history.state?.page)
})

afterAll(() => {
    application?.unmount()
    delete globalThis.route
    vi.restoreAllMocks()
})

describe('la page d’un lien de réinitialisation qui vaut', () => {
    it('montre le compte en lecture seule, et Inertia la range chiffrée dans l’historique, sans l’adresse en clair', () => {
        const champ = document.querySelector('input[autocomplete="username"]')
        const etat = window.history.state

        expect(champ.value).toBe(ADRESSE)
        expect(champ.readOnly).toBe(true)
        expect(champ.closest('form')).not.toBeNull()

        expect(etat.page).toBeInstanceOf(ArrayBuffer)
        expect(new TextDecoder().decode(new Uint8Array(etat.page))).not.toContain(PARTIE_LOCALE)
        expect(JSON.stringify(etat)).not.toContain(PARTIE_LOCALE)
        expect(contenuDe(window.sessionStorage)).not.toContain(PARTIE_LOCALE)
        expect(contenuDe(window.localStorage)).not.toContain(PARTIE_LOCALE)
    })
})
