<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Requests\Auth\NewPasswordRequest;
use App\Models\User;
use App\Support\DureeDesReponsesDAuthentification;
use App\Support\LiensDesCourriels;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use SensitiveParameter;

class NewPasswordController extends Controller
{
    /**
     * La page qu'ouvre le lien du courriel. Elle vérifie le lien avant de rien
     * montrer : l'adresse chiffrée qu'il porte doit se déchiffrer, nommer un
     * compte, et le jeton être celui, encore valable, de sa demande
     * (`lienDuCourrielOuvert()`), sans être consommé ni prolongé.
     *
     * Un lien qui vaut ouvre le formulaire : la page reçoit le jeton et
     * l'adresse chiffrée tels que le lien les porte, qu'elle renverra avec le
     * nouveau mot de passe, et l'adresse du compte (`compte`), qu'elle montre
     * en lecture seule pour qu'un gestionnaire de mots de passe range le
     * nouveau sous le bon identifiant. La page sort alors comme une page de
     * compte (`HandleInertiaRequests::traiterCommeUnePageDeCompte()`) :
     * historique chiffré, document en `no-store` ; là où le navigateur ne
     * saurait pas chiffrer, elle ne reçoit pas l'adresse. Seul ce lien la
     * montre : il suffit à changer le mot de passe du compte.
     *
     * Tout autre lien, jeton faux ou périmé, adresse qui manque ou ne se
     * déchiffre pas, lien d'avant le chiffrement (`?email=` en clair), compte
     * disparu, reçoit la même page, sans adresse ni formulaire : le refus d'un
     * jeton faux (`lienRefuse`), après le même plancher de durée que les refus
     * de la réinitialisation (`DureeDesReponsesDAuthentification`). Le courtier
     * ne vérifie l'empreinte du jeton (bcrypt) que pour un compte qui a une
     * demande en cours : sans le plancher, la durée dirait, à qui tient un vieux
     * lien, si le compte existe encore et s'il a demandé un lien depuis.
     */
    public function create(Request $request): Response
    {
        $jeton = $request->route('token');
        $adresseChiffree = $request->query('adresse');

        $compte = DureeDesReponsesDAuthentification::appeler(
            static fn (Timebox $delai): ?string => self::lienDuCourrielOuvert($jeton, $adresseChiffree, $delai),
        );

        if ($compte === null) {
            return Inertia::render('Auth/ResetPassword', [
                'lienRefuse' => trans(Password::INVALID_TOKEN),
            ]);
        }

        $montrerLeCompte = HandleInertiaRequests::traiterCommeUnePageDeCompte($request);

        return Inertia::render('Auth/ResetPassword', [
            'token' => $jeton,
            'adresse' => $adresseChiffree,
            'compte' => $montrerLeCompte ? $compte : null,
        ]);
    }

    /**
     * L'adresse du compte dont ce lien de réinitialisation vaut, ou null.
     *
     * Le courtier cherche le compte puis compare le jeton à l'empreinte de sa
     * demande, sans l'effacer ni la dater de nouveau (`getUser()`,
     * `tokenExists()`) : seul `store()` le consomme. Un lien qui vaut rend la
     * main sans attendre le plancher : sa page dit déjà qu'il vaut.
     *
     * L'adresse rendue est celle que le compte porte, pour que le gestionnaire
     * de mots de passe la range sous l'identifiant de la connexion. Comme dans
     * `resetPassword()`, elle n'est recopiée ni dans un journal ni dans un
     * message d'exception, et c'est `zend.exception_ignore_args` qui la tait
     * dans la pile.
     */
    private static function lienDuCourrielOuvert(mixed $jeton, mixed $adresseChiffree, Timebox $delai): ?string
    {
        $adresse = LiensDesCourriels::adresseDuLienDeReinitialisation($adresseChiffree);

        if (! is_string($jeton) || $adresse === null) {
            return null;
        }

        $compte = Password::getUser(['email' => $adresse]);

        if (! $compte instanceof CanResetPassword || ! Password::tokenExists($compte, $jeton)) {
            return null;
        }

        $delai->returnEarly();

        return $compte->getEmailForPasswordReset();
    }

    /**
     * Tout refus parle du jeton, même quand l'adresse n'a pas de compte : le
     * courtier rend alors `passwords.user`, dont le message dirait que
     * l'adresse est inconnue à quiconque essaie un jeton quelconque (#1951).
     * Tout refus dure aussi au moins le plancher commun des réponses
     * d'authentification (`DureeDesReponsesDAuthentification`), compte ou non :
     * le courtier ne vérifie l'empreinte du jeton (bcrypt) que pour un compte
     * qui a une demande récente. Une réussite répond sans attendre.
     *
     * Un lien dont l'adresse chiffrée manque ou ne se déchiffre pas
     * (`NewPasswordRequest::identifiantsDuLien()`) reçoit le même refus, sous
     * le même plancher, sans que le courtier soit appelé. Le refus se range
     * sous `token`, que la page affiche avec le chemin d'une nouvelle demande :
     * elle n'a pas de champ d'adresse à corriger.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(NewPasswordRequest $request): RedirectResponse
    {
        $status = DureeDesReponsesDAuthentification::appeler(
            fn (Timebox $delai): string => $this->resetPassword(
                $request->identifiantsDuLien(),
                $delai,
            ),
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'token' => [trans(Password::INVALID_TOKEN)],
        ]);
    }

    /**
     * Le courtier n'appelle la fonction qu'une fois le jeton vérifié : c'est
     * elle qui libère la réponse du plancher des refus. Le jeton est arrivé à
     * l'adresse du compte : un compte en attente de cette preuve s'ouvre
     * (`reprendreLeCompteEnAttente()`), sans être connecté (la page mène à la
     * connexion), et le titulaire d'une adresse qu'un autre a inscrite
     * reprend ainsi le compte.
     *
     * Sans identifiants (adresse du lien illisible), le refus est celui d'un
     * jeton faux. L'adresse déchiffrée n'est recopiée ni dans un journal ni
     * dans un message d'exception. Dans la pile d'une exception, c'est le
     * réglage de l'image qui la tait, `zend.exception_ignore_args = On`
     * (`docker/php-prod.ini`, tenu par `LesPilesNeGardentPasLeursArgumentsTest`) :
     * `Password::reset()` passe ces identifiants par la façade puis par le
     * gestionnaire des courtiers, deux cadres que rien ne marque, avant le
     * courtier, qui les marque. `#[SensitiveParameter]` ne tait que le cadre de
     * cette méthode : une ceinture, pas la protection. Appeler un courtier
     * nommé ôterait ces deux cadres, mais la garde de
     * `MotDePasseOublieMemeReponseTest` n'accorde à ce contrôleur que
     * `getUser`, `reset` et `tokenExists`, pas `broker` : il faudrait la
     * changer.
     *
     * @param  array{token: string, email: string, password: string, password_confirmation: string}|null  $credentials
     */
    private function resetPassword(#[SensitiveParameter] ?array $credentials, Timebox $delai): string
    {
        if ($credentials === null) {
            return Password::INVALID_TOKEN;
        }

        /** @phpstan-ignore-next-line */
        return Password::reset(
            $credentials,
            function (User $user) use ($credentials, $delai): void {
                $user->forceFill([
                    'password' => $credentials['password'],
                    'remember_token' => Str::random(60),
                ])->save();

                if ($user->estEnAttente()) {
                    self::reprendreLeCompteEnAttente($user);
                }

                event(new PasswordReset($user));

                $delai->returnEarly();
            }
        );
    }

    /**
     * Ouvre le compte en attente que la réinitialisation vient de prouver
     * (`AttendLaPreuveDeSonAdresse`), dans une seule écriture. Son nom, saisi
     * à l'inscription, a pu l'être par un autre que le titulaire de la boîte :
     * n'importe qui tape l'adresse d'un autre. Il cède la partie locale de
     * l'adresse, que le lien reçu vient de prouver, comme
     * `ReprendreUnCompteEnAttente` lui préfère le nom du fournisseur.
     */
    private static function reprendreLeCompteEnAttente(User $user): void
    {
        $user->forceFill([
            'name' => Str::before($user->email, '@'),
            'email_verified_at' => $user->freshTimestamp(),
        ])->save();

        event(new Verified($user));
    }
}
