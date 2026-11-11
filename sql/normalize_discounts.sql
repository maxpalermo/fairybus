-- ============================================================
-- fb_normalize_discounts()
--
-- Normalizza la colonna `discount` in TUTTE le tabelle dello
-- schema corrente: i valori negativi diventano positivi
-- (ABS). Lo sconto e' sempre espresso come percentuale positiva
-- e calcolato come sconto: importo = prezzo * (1 - |sconto| / 100).
--
-- La procedura scopre le tabelle da information_schema, quindi
-- copre anche tabelle aggiunte in futuro.
--
-- Installazione:
--   mariadb -u<user> -p <db> < normalize_discounts.sql
-- Esecuzione:
--   CALL fb_normalize_discounts();
-- (restituisce il conteggio righe corrette per tabella + totale)
-- ============================================================

DROP PROCEDURE IF EXISTS fb_normalize_discounts;

DELIMITER $$
CREATE PROCEDURE fb_normalize_discounts()
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE t VARCHAR(128);
    DECLARE cur CURSOR FOR
        SELECT TABLE_NAME
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND COLUMN_NAME = 'discount';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    DROP TEMPORARY TABLE IF EXISTS _fb_discount_fix_report;
    CREATE TEMPORARY TABLE _fb_discount_fix_report (
        tabella        VARCHAR(128) PRIMARY KEY,
        righe_corrette INT NOT NULL DEFAULT 0
    ) ENGINE = MEMORY;

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO t;
        IF done THEN
            LEAVE read_loop;
        END IF;

        SET @sql = CONCAT('UPDATE `', t, '` SET discount = ABS(discount) WHERE discount < 0');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        INSERT INTO _fb_discount_fix_report (tabella, righe_corrette) VALUES (t, ROW_COUNT());
        DEALLOCATE PREPARE stmt;
    END LOOP;
    CLOSE cur;

    SELECT * FROM _fb_discount_fix_report;
    SELECT COALESCE(SUM(righe_corrette), 0) AS totale_correzioni
      FROM _fb_discount_fix_report;
END $$
DELIMITER ;

-- ============================================================
-- fb_fix_maintenance_discounts()
--
-- Le righe manutenzione agganciate a un carico (id_load ->
-- fb_document_detail) devono riportare lo sconto originale del
-- carico: la procedura copia ABS(dd.discount) sulle righe dove
-- differisce, cosi' il prezzo finale (lordo - sconto) torna
-- corretto. Rilanciabile in sicurezza.
-- ============================================================

DROP PROCEDURE IF EXISTS fb_fix_maintenance_discounts;

DELIMITER $$
CREATE PROCEDURE fb_fix_maintenance_discounts()
BEGIN
    UPDATE fb_maintenance_detail md
    JOIN fb_document_detail dd ON dd.id_document_detail = md.id_load
    SET md.discount = ABS(dd.discount)
    WHERE md.id_load IS NOT NULL
      AND ABS(md.discount) <> ABS(dd.discount);

    SELECT ROW_COUNT() AS righe_corrette;
END $$
DELIMITER ;
