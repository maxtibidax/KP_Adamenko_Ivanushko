DROP SCHEMA public CASCADE;
CREATE SCHEMA public;

CREATE TABLE Client (
    client_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_full_name VARCHAR(100) NOT NULL,
    phone_number VARCHAR(20) NOT NULL
);

CREATE TABLE Manager (
    manager_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    manager_full_name VARCHAR(100) NOT NULL
);

CREATE TABLE Dish (
    dish_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dish_name VARCHAR(100) NOT NULL,
    cost_price NUMERIC(10,2) NOT NULL,
    sale_price NUMERIC(10,2) NOT NULL,
    price_category VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE
);

CREATE TABLE Product (
    product_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_name VARCHAR(100) NOT NULL
);

CREATE TABLE Supplier (
    supplier_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    supplier_name VARCHAR(100) NOT NULL
);

CREATE TABLE Orders (
    order_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    status VARCHAR(100) DEFAULT 'В обработке' NOT NULL,
    manager_id INT NOT NULL,
    client_id INT NOT NULL,
    event_date DATE NOT NULL,
    total_cost NUMERIC(10,2) DEFAULT 0 NOT NULL,
    event_type VARCHAR(50) DEFAULT 'Банкет',
    prepayment_amount NUMERIC(10, 2) DEFAULT 0.00,
    is_fully_paid BOOLEAN DEFAULT FALSE
);

CREATE TABLE Order_Details (
    dish_id INT NOT NULL,
    order_id INT NOT NULL,
    serving_number NUMERIC(10,2) NOT NULL,
    CONSTRAINT PK_ORDER_DETAILS PRIMARY KEY (dish_id, order_id)
);

CREATE TABLE Recipe (
    product_id INT NOT NULL,
    dish_id INT NOT NULL,
    number_in_recipe NUMERIC(10,3) NOT NULL,
    CONSTRAINT PK_RECIPE PRIMARY KEY (product_id, dish_id)
);

CREATE TABLE Supplier_Request (
    request_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    request_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    manager_id INT NOT NULL,
    supplier_id INT NOT NULL,
    status VARCHAR(50) DEFAULT 'В пути' NOT NULL,
    linked_order_id INT
);

CREATE TABLE Request_Details (
    product_id INT NOT NULL,
    request_id INT NOT NULL,
    products_number NUMERIC(10,2) NOT NULL,
    CONSTRAINT PK_REQUEST_DETAILS PRIMARY KEY (product_id, request_id)
);

CREATE TABLE operation_log (
    log_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    operation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    operation_type VARCHAR(50),
    table_name VARCHAR(50),
    record_id INT,
    description TEXT,
    user_info TEXT
);

CREATE TABLE product_stock (
    product_id INT PRIMARY KEY,
    quantity NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (quantity >= 0),
    min_quantity NUMERIC(10,2) NOT NULL DEFAULT 10,
    last_restock_date DATE
);

CREATE TABLE reserved_products (
    reservation_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity NUMERIC(10,2) NOT NULL,
    reservation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE app_user (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    roles JSONB NOT NULL DEFAULT '["ROLE_USER"]',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE Orders ADD CONSTRAINT FK_ORDER_CLIENT FOREIGN KEY (client_id) REFERENCES Client (client_id) ON DELETE RESTRICT;
ALTER TABLE Orders ADD CONSTRAINT FK_ORDER_MANAGER FOREIGN KEY (manager_id) REFERENCES Manager (manager_id) ON DELETE RESTRICT;
ALTER TABLE Order_Details ADD CONSTRAINT FK_OD_DISH FOREIGN KEY (dish_id) REFERENCES Dish (dish_id) ON DELETE CASCADE;
ALTER TABLE Order_Details ADD CONSTRAINT FK_OD_ORDER FOREIGN KEY (order_id) REFERENCES Orders (order_id) ON DELETE CASCADE;
ALTER TABLE Recipe ADD CONSTRAINT FK_RECIPE_DISH FOREIGN KEY (dish_id) REFERENCES Dish (dish_id) ON DELETE CASCADE;
ALTER TABLE Recipe ADD CONSTRAINT FK_RECIPE_PRODUCT FOREIGN KEY (product_id) REFERENCES Product (product_id) ON DELETE RESTRICT;
ALTER TABLE Supplier_Request ADD CONSTRAINT FK_SR_MANAGER FOREIGN KEY (manager_id) REFERENCES Manager (manager_id) ON DELETE RESTRICT;
ALTER TABLE Supplier_Request ADD CONSTRAINT FK_SR_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES Supplier (supplier_id) ON DELETE RESTRICT;
ALTER TABLE Supplier_Request ADD CONSTRAINT FK_SR_ORDER FOREIGN KEY (linked_order_id) REFERENCES Orders (order_id) ON DELETE SET NULL;
ALTER TABLE Request_Details ADD CONSTRAINT FK_RD_REQUEST FOREIGN KEY (request_id) REFERENCES Supplier_Request (request_id) ON DELETE CASCADE;
ALTER TABLE Request_Details ADD CONSTRAINT FK_RD_PRODUCT FOREIGN KEY (product_id) REFERENCES Product (product_id) ON DELETE RESTRICT;
ALTER TABLE product_stock ADD CONSTRAINT FK_STOCK_PRODUCT FOREIGN KEY (product_id) REFERENCES Product (product_id) ON DELETE CASCADE;
ALTER TABLE reserved_products ADD CONSTRAINT FK_RES_ORDER FOREIGN KEY (order_id) REFERENCES Orders (order_id) ON DELETE CASCADE;
ALTER TABLE reserved_products ADD CONSTRAINT FK_RES_PRODUCT FOREIGN KEY (product_id) REFERENCES Product (product_id) ON DELETE CASCADE;

CREATE INDEX idx_orders_event_date ON Orders (event_date);
CREATE INDEX idx_orders_status     ON Orders (status);
CREATE INDEX idx_orders_client_id  ON Orders (client_id);
CREATE INDEX idx_orders_manager_id ON Orders (manager_id);
CREATE UNIQUE INDEX idx_orders_client_date ON Orders (client_id, event_date);

-- Secondary indexes for performance and practical query support
CREATE INDEX idx_supplier_request_status     ON Supplier_Request (status);
CREATE INDEX idx_supplier_request_supplier_id ON Supplier_Request (supplier_id);
CREATE INDEX idx_supplier_request_date       ON Supplier_Request (request_date);
CREATE INDEX idx_supplier_request_manager_id ON Supplier_Request (manager_id);
CREATE INDEX idx_dish_is_active              ON Dish (is_active);
CREATE INDEX idx_dish_price_category         ON Dish (price_category);
CREATE INDEX idx_product_stock_low           ON product_stock (quantity, min_quantity);
CREATE INDEX idx_operation_log_table_record  ON operation_log (table_name, record_id);
CREATE INDEX idx_operation_log_date          ON operation_log (operation_date);
CREATE INDEX idx_reserved_products_order     ON reserved_products (order_id);
CREATE INDEX idx_reserved_products_product   ON reserved_products (product_id);
CREATE INDEX idx_order_details_order_id      ON Order_Details (order_id);
CREATE INDEX idx_recipe_dish_id              ON Recipe (dish_id);
CREATE INDEX idx_request_details_request_id  ON Request_Details (request_id);
CREATE INDEX idx_app_user_username           ON app_user (username);

CREATE OR REPLACE FUNCTION log_operation(p_operation_type VARCHAR, p_table_name VARCHAR, p_record_id INT, p_description TEXT)
RETURNS VOID AS $$
BEGIN
    INSERT INTO operation_log (operation_type, table_name, record_id, description)
    VALUES (p_operation_type, p_table_name, p_record_id, p_description);
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION set_dish_price_category() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.sale_price < 300 THEN NEW.price_category := 'Эконом';
    ELSIF NEW.sale_price <= 600 THEN NEW.price_category := 'Стандарт';
    ELSE NEW.price_category := 'Премиум';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_set_price_category BEFORE INSERT OR UPDATE OF sale_price ON Dish
FOR EACH ROW EXECUTE FUNCTION set_dish_price_category();

-- profit вычисляется в запросах как (sale_price - cost_price), хранимое поле удалено.

CREATE OR REPLACE FUNCTION check_order_date_and_status() RETURNS TRIGGER AS $$
BEGIN
    IF (TG_OP = 'INSERT') OR (NEW.event_date <> OLD.event_date) THEN
        IF NEW.event_date < CURRENT_DATE AND NEW.status NOT IN ('Выполнен', 'Отменен') THEN
            RAISE EXCEPTION 'Дата нового или активного заказа не может быть в прошлом: %', NEW.event_date;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_order_date_check BEFORE INSERT OR UPDATE ON Orders
FOR EACH ROW EXECUTE FUNCTION check_order_date_and_status();

CREATE OR REPLACE FUNCTION prevent_edit_cancelled_order() RETURNS TRIGGER AS $$
BEGIN
    IF OLD.status = 'Отменен' THEN
        RAISE EXCEPTION 'Ошибка: Нельзя редактировать отмененный заказ (ID: %)', OLD.order_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_prevent_edit_cancelled BEFORE UPDATE ON Orders
FOR EACH ROW EXECUTE FUNCTION prevent_edit_cancelled_order();

CREATE OR REPLACE FUNCTION check_order_payment_status() RETURNS TRIGGER AS $$
BEGIN
    -- Менять статус на «Забронирован» только при реальном изменении предоплаты,
    -- а не при обновлении total_cost (seed-скрипты, пересчёт состава).
    IF NEW.prepayment_amount > 0
       AND (TG_OP = 'INSERT' OR (OLD.prepayment_amount IS DISTINCT FROM NEW.prepayment_amount AND OLD.status = 'В обработке'))
    THEN
        NEW.status := 'Забронирован';
    END IF;

    NEW.is_fully_paid := (NEW.prepayment_amount >= NEW.total_cost AND NEW.total_cost > 0);

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_payment_status BEFORE INSERT OR UPDATE OF prepayment_amount, total_cost ON Orders
FOR EACH ROW EXECUTE FUNCTION check_order_payment_status();

CREATE OR REPLACE FUNCTION log_order_status_change() RETURNS TRIGGER AS $$
BEGIN
    IF OLD.status <> NEW.status THEN
        PERFORM log_operation('UPDATE_STATUS', 'Orders', NEW.order_id, 'Статус изменен с ' || OLD.status || ' на ' || NEW.status);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_log_order_status AFTER UPDATE OF status ON Orders
FOR EACH ROW EXECUTE FUNCTION log_order_status_change();

CREATE OR REPLACE FUNCTION close_order_reservations_on_final_status() RETURNS TRIGGER AS $$
DECLARE
    v_rec RECORD;
    v_deleted_count INT;
BEGIN
    IF NEW.status = 'Выполнен' AND OLD.status <> 'Выполнен' THEN
        DELETE FROM reserved_products WHERE order_id = NEW.order_id;
        GET DIAGNOSTICS v_deleted_count = ROW_COUNT;

        IF v_deleted_count > 0 THEN
            PERFORM log_operation('CLOSE_RESERVE', 'Orders', NEW.order_id, 'Заказ выполнен, резерв продуктов закрыт');
        END IF;
    ELSIF NEW.status = 'Отменен' AND OLD.status <> 'Отменен' THEN
        FOR v_rec IN
            SELECT product_id, quantity
            FROM reserved_products
            WHERE order_id = NEW.order_id
        LOOP
            UPDATE product_stock
            SET quantity = quantity + v_rec.quantity
            WHERE product_id = v_rec.product_id;
        END LOOP;

        DELETE FROM reserved_products WHERE order_id = NEW.order_id;
        GET DIAGNOSTICS v_deleted_count = ROW_COUNT;

        IF v_deleted_count > 0 THEN
            PERFORM log_operation('CLOSE_RESERVE', 'Orders', NEW.order_id, 'Заказ отменен, резерв продуктов возвращен на склад');
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_close_order_reservations AFTER UPDATE OF status ON Orders
FOR EACH ROW EXECUTE FUNCTION close_order_reservations_on_final_status();

CREATE OR REPLACE FUNCTION apply_received_supplier_request_detail() RETURNS TRIGGER AS $$
DECLARE
    v_status VARCHAR;
    v_request_date DATE;
BEGIN
    SELECT status, request_date INTO v_status, v_request_date
    FROM Supplier_Request
    WHERE request_id = NEW.request_id;

    IF v_status = 'Получено' THEN
        UPDATE product_stock
        SET quantity = quantity + NEW.products_number,
            last_restock_date = v_request_date
        WHERE product_id = NEW.product_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_received_request_detail AFTER INSERT ON Request_Details
FOR EACH ROW EXECUTE FUNCTION apply_received_supplier_request_detail();

CREATE OR REPLACE FUNCTION apply_received_supplier_request_status() RETURNS TRIGGER AS $$
DECLARE
    v_detail RECORD;
BEGIN
    IF NEW.status = 'Получено' AND OLD.status <> 'Получено' THEN
        FOR v_detail IN
            SELECT product_id, products_number
            FROM Request_Details
            WHERE request_id = NEW.request_id
        LOOP
            UPDATE product_stock
            SET quantity = quantity + v_detail.products_number,
                last_restock_date = NEW.request_date
            WHERE product_id = v_detail.product_id;
        END LOOP;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_received_request_status AFTER UPDATE OF status ON Supplier_Request
FOR EACH ROW EXECUTE FUNCTION apply_received_supplier_request_status();

CREATE OR REPLACE PROCEDURE create_complex_order_full(
    IN p_client_id INT,
    IN p_manager_id INT,
    IN p_event_date DATE,
    IN p_event_type VARCHAR,
    IN p_dishes JSONB,
    OUT p_order_id INT,
    OUT p_total_cost NUMERIC,
    OUT p_status VARCHAR(20),

    OUT p_message TEXT
)
LANGUAGE plpgsql AS $$
DECLARE
    v_dish_record JSONB; v_dish_id INT; v_quantity NUMERIC; v_dish_price NUMERIC;
    v_product_id INT; v_required_qty NUMERIC; v_available_qty NUMERIC;
BEGIN
    p_status := 'ERROR'; p_message := ''; p_total_cost := 0;

    INSERT INTO Orders (client_id, manager_id, event_date, event_type, status, total_cost)
    VALUES (p_client_id, p_manager_id, p_event_date, p_event_type, 'В обработке', 0)
    RETURNING order_id INTO p_order_id;

    FOR v_dish_record IN SELECT * FROM jsonb_array_elements(p_dishes) LOOP
        v_dish_id := (v_dish_record->>'dish_id')::INT;
        v_quantity := (v_dish_record->>'quantity')::NUMERIC;

        SELECT sale_price INTO v_dish_price FROM Dish WHERE dish_id = v_dish_id AND is_active = TRUE;
        IF v_dish_price IS NULL THEN
            RAISE EXCEPTION 'Блюдо (ID: %) недоступно или неактивно!', v_dish_id;
        END IF;

        INSERT INTO Order_Details (order_id, dish_id, serving_number)
        VALUES (p_order_id, v_dish_id, v_quantity);

        p_total_cost := p_total_cost + (v_dish_price * v_quantity);

        FOR v_product_id, v_required_qty IN
            SELECT r.product_id, r.number_in_recipe * v_quantity
            FROM Recipe r WHERE r.dish_id = v_dish_id
        LOOP
            SELECT quantity INTO v_available_qty FROM product_stock WHERE product_id = v_product_id FOR UPDATE;
            IF v_available_qty IS NULL THEN v_available_qty := 0; END IF;

            IF v_available_qty >= v_required_qty THEN
                UPDATE product_stock SET quantity = quantity - v_required_qty WHERE product_id = v_product_id;
                INSERT INTO reserved_products (order_id, product_id, quantity) VALUES (p_order_id, v_product_id, v_required_qty);
            ELSE
                RAISE EXCEPTION 'Недостаточно продукта (ID: %) на складе! Нужно: %, Доступно: %',
                                v_product_id, v_required_qty, v_available_qty;
            END IF;
        END LOOP;
    END LOOP;

    UPDATE Orders SET total_cost = p_total_cost WHERE order_id = p_order_id;

    p_status := 'SUCCESS';
    p_message := 'Заказ успешно создан, продукты зарезервированы.';

EXCEPTION WHEN OTHERS THEN
    p_status := 'ERROR';
    p_message := 'Ошибка создания заказа: ' || SQLERRM;
END;
$$;

CREATE OR REPLACE PROCEDURE cancel_order_logic(p_order_id INT)
LANGUAGE plpgsql AS $$
DECLARE
    v_status VARCHAR; v_rec RECORD;
BEGIN
    SELECT status INTO v_status FROM Orders WHERE order_id = p_order_id;
    IF v_status = 'Отменен' THEN RAISE EXCEPTION 'Заказ уже отменен!'; END IF;
    IF v_status = 'Выполнен' THEN RAISE EXCEPTION 'Нельзя отменить выполненный заказ!'; END IF;

    FOR v_rec IN (SELECT product_id, quantity FROM reserved_products WHERE order_id = p_order_id) LOOP
        UPDATE product_stock SET quantity = quantity + v_rec.quantity WHERE product_id = v_rec.product_id;
    END LOOP;

    DELETE FROM reserved_products WHERE order_id = p_order_id;
    UPDATE Orders SET status = 'Отменен' WHERE order_id = p_order_id;
    PERFORM log_operation('CANCEL', 'Orders', p_order_id, 'Заказ отменен, продукты возвращены');
END;
$$;

INSERT INTO Client (client_full_name, phone_number) VALUES
('Смирнов Павел Александрович', '9012345678'), ('Волкова Екатерина Олеговна', '9023456789'),
('Николаев Иван Петрович', '9034567890'), ('Орлова София Дмитриевна', '9045678901');

INSERT INTO Manager (manager_full_name) VALUES
('Иванов Алексей Петрович'), ('Петрова Мария Сергеевна');

INSERT INTO Supplier (supplier_name) VALUES
('ООО "ПродуктыОпт"'), ('ИП Сидоров - Мясо');

INSERT INTO Product (product_name) VALUES
('Куриное филе'), ('Помидоры'), ('Салат Айсберг'), ('Сыр Пармезан'), ('Сухарики'), ('Говядина');

INSERT INTO product_stock (product_id, quantity, min_quantity, last_restock_date)
SELECT product_id, 100, 10, CURRENT_DATE FROM Product;

INSERT INTO Dish (dish_name, cost_price, sale_price) VALUES
('Салат Цезарь', 180.50, 450.00),
('Стейк из говядины', 350.25, 890.00);

INSERT INTO Recipe (product_id, dish_id, number_in_recipe) VALUES
(1, 1, 0.2), (3, 1, 0.15), (4, 1, 0.05), (5, 1, 0.03),
(6, 2, 0.3);

INSERT INTO Orders (status, manager_id, client_id, event_date, total_cost, event_type, is_fully_paid) VALUES
('Выполнен', 1, 1, CURRENT_DATE - INTERVAL '5 days', 5000.00, 'Свадьба', TRUE),
('В обработке', 2, 2, CURRENT_DATE + INTERVAL '5 days', 7500.00, 'Корпоратив', FALSE);

-- Default application users (passwords: admin / manager)
INSERT INTO app_user (username, password, full_name, roles) VALUES
('admin', '$2y$10$AAlfx5lSJEW2lOpUGIHPIOejeWtWTadnmjYjHJr.2Ks2AexM9Hhcq', 'Администратор системы', '["ROLE_ADMIN"]'),
('manager', '$2y$10$I3eYWxAzfaFJCYIBMoKA/e4IGGa6jqGUk1B6GHXwEBfFyfnjpCB96', 'Менеджер по умолчанию', '["ROLE_MANAGER"]');

-- ================================================================
-- VIEWS — пользовательские представления с практическим смыслом
-- ================================================================

-- VIEW 1: v_active_orders_overview
-- Обзор активных заказов с полной детализацией: клиент, менеджер, блюда, оплата.
-- Практическая ценность: единый запрос заменяет многократные JOIN при формировании
-- повестки дня, списков контактов для обзвона, сводок по предоплатам.
CREATE OR REPLACE VIEW v_active_orders_overview AS
SELECT
    o.order_id,
    o.event_date,
    o.event_type,
    o.status,
    o.total_cost,
    o.prepayment_amount,
    o.is_fully_paid,
    o.total_cost - o.prepayment_amount AS remaining_payment,
    c.client_full_name,
    c.phone_number,
    m.manager_full_name,
    COALESCE(string_agg(d.dish_name || ' x ' || od.serving_number, ', ' ORDER BY d.dish_name), '') AS dishes_summary
FROM orders o
JOIN client c ON c.client_id = o.client_id
JOIN manager m ON m.manager_id = o.manager_id
LEFT JOIN order_details od ON od.order_id = o.order_id
LEFT JOIN dish d ON d.dish_id = od.dish_id
WHERE o.status NOT IN ('Выполнен', 'Отменен')
GROUP BY o.order_id, c.client_full_name, c.phone_number, m.manager_full_name
ORDER BY o.event_date;

-- VIEW 2: v_stock_with_supply_info
-- Состояние склада с привязкой к поставщикам и ближайшим ожидающим поставкам.
-- Практическая ценность: логист и менеджер видят, какие продукты в дефиците,
-- от какого поставщика обычно поступает каждый продукт, и есть ли уже заявка в пути.
CREATE OR REPLACE VIEW v_stock_with_supply_info AS
SELECT
    p.product_id,
    p.product_name,
    s.quantity,
    s.min_quantity,
    s.last_restock_date,
    CASE WHEN s.quantity <= s.min_quantity THEN TRUE ELSE FALSE END AS is_low,
    s.min_quantity - s.quantity AS shortage,
    COALESCE(sup.primary_supplier_name, '') AS primary_supplier,
    COALESCE(latest.pending_date::TEXT, '') AS next_expected_delivery,
    COALESCE(latest.pending_quantity, 0) AS pending_quantity
FROM product_stock s
JOIN product p ON p.product_id = s.product_id
LEFT JOIN LATERAL (
    SELECT sr.supplier_id, sup2.supplier_name AS primary_supplier_name
    FROM request_details rd
    JOIN supplier_request sr ON sr.request_id = rd.request_id
    JOIN supplier sup2 ON sup2.supplier_id = sr.supplier_id
    WHERE rd.product_id = p.product_id
    GROUP BY sr.supplier_id, sup2.supplier_name
    ORDER BY SUM(rd.products_number) DESC
    LIMIT 1
) sup ON TRUE
LEFT JOIN LATERAL (
    SELECT sr.request_date AS pending_date, SUM(rd.products_number) AS pending_quantity
    FROM request_details rd
    JOIN supplier_request sr ON sr.request_id = rd.request_id
    WHERE rd.product_id = p.product_id AND sr.status = 'В пути'
    GROUP BY sr.request_date
    ORDER BY sr.request_date
    LIMIT 1
) latest ON TRUE
ORDER BY is_low DESC, shortage DESC, p.product_name;
