<script setup>
/**
 * La page qu'ouvre le lien du courriel « mot de passe oublié ».
 *
 * Le serveur vérifie le lien avant de rien montrer. Un lien qui vaut ouvre le
 * formulaire : la page reçoit le jeton et l'adresse chiffrée tels que le lien
 * les porte (`token`, `adresse`), qu'elle renvoie avec le nouveau mot de passe
 * sans les lire, et l'adresse du compte en clair (`compte`). Elle la montre en
 * lecture seule, sous « Compte », dans un champ `autocomplete="username"` du
 * formulaire : un gestionnaire de mots de passe range ainsi le nouveau sous le
 * bon identifiant. Ce champ n'est pas envoyé : le formulaire ne porte que la
 * valeur chiffrée. Pour le garder sans le montrer, passer `MONTRER_LE_COMPTE`
 * à `false` : le champ reste dans le formulaire, caché (`hidden`), comme le
 * recommandent les gestionnaires. Le serveur ne donne pas l'adresse là où le
 * navigateur ne saurait pas chiffrer l'historique : le champ manque alors.
 * Une fois montée, la page vide la page initiale du document
 * (`viderLaPageInitialeDuDocument()`), que le chiffrement de l'historique ne
 * couvre pas et qu'Inertia garderait, compte, jeton et valeur chiffrée
 * compris, tant que l'onglet ne recharge pas.
 *
 * Un lien refusé (jeton faux ou périmé, lien d'avant le chiffrement) le dit
 * dès l'ouverture (`lienRefuse`), avec le chemin d'une nouvelle demande, sans
 * formulaire. Un envoi refusé plus tard revient avec l'erreur sous `token`,
 * montrée au même endroit.
 */
import { computed, onMounted } from 'vue'
import InputError from '@/Components/Form/InputError.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { viderLaPageInitialeDuDocument } from '@/Utils/historiqueDuCompte'
import { NOUVEAU_MOT_DE_PASSE_NON_CONFIRME, direSansReseau } from '@/Utils/visitesHorsLigne'

/** Le compte du lien se voit ; à `false`, il reste dans le formulaire, caché. */
const MONTRER_LE_COMPTE = true

const props = defineProps({
    token: String,
    adresse: String,
    compte: String,
    lienRefuse: String,
})

const form = useForm({
    token: props.token,
    adresse: props.adresse,
    password: '',
    password_confirmation: '',
})

const refus = computed(() => props.lienRefuse || form.errors.token)

onMounted(() => viderLaPageInitialeDuDocument(document, 'Auth/ResetPassword'))

const submit = () => {
    form.post(route('password.store'), {
        onNetworkError: direSansReseau(NOUVEAU_MOT_DE_PASSE_NON_CONFIRME),
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <GuestLayout>
        <Head title="Réinitialiser le mot de passe" />

        <div class="mb-6 text-center">
            <h2 class="titre-section">Nouveau mot de passe</h2>
            <p class="text-text-muted mt-1">Choisis un nouveau mot de passe sécurisé</p>
        </div>

        <div v-if="refus" class="mb-4" data-testid="lien-refuse">
            <InputError id="lien-refuse" :message="refus" />
            <Link
                :href="route('password.request')"
                class="text-accent-primary-deep hover:text-accent-tertiary-deep mt-2 inline-block text-sm font-semibold transition-colors"
            >
                Demander un nouveau lien
            </Link>
        </div>

        <form v-if="!lienRefuse" @submit.prevent="submit" class="space-y-4">
            <div v-if="compte" :hidden="!MONTRER_LE_COMPTE" data-testid="compte-du-lien">
                <GlassInput
                    id="compte-du-lien"
                    :model-value="compte"
                    type="email"
                    name="email"
                    label="Compte"
                    autocomplete="username"
                    readonly
                />
            </div>

            <GlassInput
                v-model="form.password"
                type="password"
                label="Nouveau mot de passe"
                placeholder="••••••••"
                :error="form.errors.password"
                autocomplete="new-password"
                required
                autofocus
            />

            <GlassInput
                v-model="form.password_confirmation"
                type="password"
                label="Confirmer le mot de passe"
                placeholder="••••••••"
                :error="form.errors.password_confirmation"
                autocomplete="new-password"
                required
            />

            <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                Réinitialiser
            </GlassButton>
        </form>
    </GuestLayout>
</template>
