-- 0004_totp_replay.sql — anti-rejeu du second facteur
--
-- RFC 6238 §5.2 : « The verifier MUST NOT accept the second attempt of the OTP
-- after the successful validation has been issued for the first OTP. »
--
-- Un code TOTP reste mathematiquement valide pendant trente secondes, et notre
-- tolerance d'un pas porte cette fenetre a une minute et demie. Sans memoire du
-- dernier compteur accepte, un code observe — par-dessus l'epaule, dans un
-- journal de proxy — resservirait pendant tout ce temps.
--
-- Le compteur, et non le code : on ne conserve aucun secret supplementaire, et
-- la comparaison est un simple « strictement superieur au dernier accepte ».
--
-- Migration distincte de 0003 : la regle a ete decouverte en ecrivant le test de
-- rejeu, et 01-modele-de-donnees §8 impose un fichier par changement plutot
-- qu'une reecriture de la migration precedente.
ALTER TABLE users
  ADD COLUMN totp_last_counter BIGINT UNSIGNED NULL AFTER totp_secret;
