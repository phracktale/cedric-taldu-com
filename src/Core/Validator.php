<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\ValidationFailed;

/**
 * Validation declarative des entrees.
 *
 * Deux principes de src/CLAUDE.md :
 *
 *  - toute entree est validee par un schema explicite AVANT usage ;
 *  - pas d'affectation en masse : ce qui n'est pas declare n'est pas rendu.
 *
 * Toutes les erreurs sont collectees avant d'etre levees ensemble : un
 * formulaire qui n'en signale qu'une a la fois fait recommencer le visiteur
 * autant de fois qu'il y a de champs fautifs.
 */
final class Validator
{
    /**
     * @param array<string, string> $input
     * @param array<string, Rule>   $schema
     * @return array<string, string|int>
     *
     * @throws ValidationFailed
     */
    public function validate(array $input, array $schema): array
    {
        $clean = [];
        $errors = [];

        foreach ($schema as $field => $rule) {
            $raw = $input[$field] ?? '';

            // Le controle des caracteres interdits precede le nettoyage : trim()
            // supprime \0, \r et \n en bordure de chaine, et laisserait donc
            // passer en silence une tentative d'injection d'en-tete.
            if (self::containsControl($raw, self::allowsNewlines($rule))) {
                $errors[$field] = self::messageFor($rule);
                continue;
            }

            $value = trim($raw);

            if ($value === '') {
                if ($rule->required) {
                    $errors[$field] = 'Ce champ est obligatoire.';
                }
                continue;
            }

            if (mb_strlen($value, 'UTF-8') > $rule->max) {
                $errors[$field] = sprintf('Ce champ ne peut pas dépasser %d caractères.', $rule->max);
                continue;
            }

            $checked = $this->check($rule, $value);

            if ($checked === null) {
                $errors[$field] = self::messageFor($rule);
                continue;
            }

            $clean[$field] = $checked;
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $clean;
    }

    /**
     * @return string|int|null null signale un refus ; le message est choisi par
     *         l'appelant, jamais construit a partir de la valeur recue
     */
    private function check(Rule $rule, string $value): string|int|null
    {
        return match ($rule->type) {
            Rule::TYPE_TEXT, Rule::TYPE_MULTILINE => $value,
            Rule::TYPE_EMAIL => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value,
            Rule::TYPE_SLUG => preg_match('#^' . Route::SLUG . '$#D', $value) === 1 ? $value : null,
            Rule::TYPE_ID => preg_match('#^' . Route::ID . '$#D', $value) === 1 ? (int) $value : null,
            Rule::TYPE_AMONG => in_array($value, $rule->allowed, true) ? $value : null,
            default => null,
        };
    }

    /**
     * Seul un champ de texte libre accepte des sauts de ligne. Partout ailleurs,
     * CR et LF sont le vecteur d'injection d'en-tetes de messagerie
     * (06-securite §6.6). L'octet nul n'est tolere nulle part.
     */
    private static function allowsNewlines(Rule $rule): bool
    {
        return $rule->type === Rule::TYPE_MULTILINE;
    }

    private static function containsControl(string $value, bool $allowNewlines): bool
    {
        $pattern = $allowNewlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';

        return preg_match($pattern, $value) === 1;
    }

    private static function messageFor(Rule $rule): string
    {
        return match ($rule->type) {
            Rule::TYPE_EMAIL => 'Cette adresse électronique n’est pas valide.',
            Rule::TYPE_SLUG => 'Cet identifiant d’URL n’est pas valide.',
            Rule::TYPE_ID => 'Cet identifiant n’est pas valide.',
            Rule::TYPE_AMONG => 'Cette valeur n’est pas proposée.',
            default => 'Ce champ contient des caractères non autorisés.',
        };
    }
}
