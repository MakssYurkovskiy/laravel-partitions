SET SESSION cte_max_recursion_depth = 1000000;

DROP TABLE IF EXISTS transactions;

CREATE TABLE transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id, created_at),
    KEY transactions_account_id_index (account_id)
);

INSERT INTO transactions (account_id, amount, created_at)
WITH RECURSIVE seq (n) AS (
    SELECT 0
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < @rows - 1
)
SELECT
    1 + (n % 5000),
    ROUND(RAND() * 500, 2),
    TIMESTAMPADD(SECOND, FLOOR(n / @rows * @days * 86400), @first)
FROM seq;
