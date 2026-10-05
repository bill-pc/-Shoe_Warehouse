--
-- PostgreSQL database dump
--

\restrict jz12BYZupdCmSPUjRCAsMlDQBTcaFjdSNbfv3mSppnEvDgCCNlJSXJ4CL5AX3TG

-- Dumped from database version 17.9
-- Dumped by pg_dump version 17.9

-- Started on 2026-06-02 05:06:14

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- TOC entry 26 (class 2615 OID 50843)
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--

-- *not* creating schema, since initdb creates it


--
-- TOC entry 5327 (class 0 OID 0)
-- Dependencies: 26
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA public IS '';


--
-- TOC entry 2 (class 3079 OID 50844)
-- Name: vector; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;


--
-- TOC entry 5328 (class 0 OID 0)
-- Dependencies: 2
-- Name: EXTENSION vector; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION vector IS 'vector data type and ivfflat and hnsw access methods';


--
-- TOC entry 282 (class 1255 OID 51172)
-- Name: fn_clean_variant_from_shelves(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_clean_variant_from_shelves() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    r RECORD;
    v_tier_key TEXT;
    v_slot_key TEXT;
    v_new_layout JSONB;
    v_modified BOOLEAN;
    v_new_array JSONB;
    v_target_id INT;
BEGIN
    -- Xác định variant_id cần loại bỏ khỏi kệ hàng
    IF TG_OP = 'DELETE' THEN
        v_target_id := OLD.variant_id;
    ELSIF TG_OP = 'UPDATE' THEN
        -- Chỉ kích hoạt khi trạng thái is_deleted chuyển từ false -> true (Xóa mềm)
        IF OLD.is_deleted = false AND NEW.is_deleted = true THEN
            v_target_id := NEW.variant_id;
        ELSE
            -- Không phải hành động xóa mềm -> Bỏ qua không xử lý
            RETURN NEW;
        END IF;
    END IF;

    -- Lặp qua từng kệ hàng trong kho
    FOR r IN SELECT shelf_id, layout FROM public.shelves LOOP
        v_new_layout := r.layout;
        v_modified := FALSE;

        -- Duyệt qua từng tầng (tier) của kệ
        FOR v_tier_key IN SELECT jsonb_object_keys(r.layout) LOOP
            -- Duyệt qua từng ô chứa giày (slot) trong tầng
            FOR v_slot_key IN SELECT jsonb_object_keys(r.layout -> v_tier_key) LOOP
                
                -- Trích xuất mảng JSONB dưới dạng TEXT (loại bỏ dấu ngoặc kép của String nếu có)
                -- Lọc bỏ phần tử trùng với v_target_id và gom lại thành mảng JSONB mới
                SELECT jsonb_agg(elem::int) INTO v_new_array
                FROM jsonb_array_elements_text(r.layout -> v_tier_key -> v_slot_key) AS elem
                WHERE elem::int != v_target_id;

                -- Nếu mảng mới trống (tất cả giày bị lọc bỏ hoặc mảng ban đầu rỗng)
                IF v_new_array IS NULL THEN
                    v_new_array := '[]'::jsonb;
                END IF;

                -- So sánh độ dài: Nếu có sự thay đổi về số lượng giày trong ô
                IF jsonb_array_length(r.layout -> v_tier_key -> v_slot_key) != jsonb_array_length(v_new_array) THEN
                    v_new_layout := jsonb_set(v_new_layout, ARRAY[v_tier_key, v_slot_key], v_new_array);
                    v_modified := TRUE;
                END IF;
            END LOOP;
        END LOOP;

        -- Nếu layout của kệ này có sự thay đổi -> Tiến hành cập nhật Database
        IF v_modified THEN
            UPDATE public.shelves 
            SET layout = v_new_layout 
            WHERE shelf_id = r.shelf_id;
        END IF;
    END LOOP;

    -- Trả về kết quả để hoàn tất giao dịch
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    ELSE
        RETURN NEW;
    END IF;
END;
$$;


--
-- TOC entry 276 (class 1255 OID 51173)
-- Name: fn_log_system_changes(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_log_system_changes() RETURNS trigger
    LANGUAGE plpgsql
    AS $_$
DECLARE
    v_user_id INT;
    v_pk_col TEXT;
    v_target_id INT;
BEGIN
    -- Lấy ID user từ session PHP truyền xuống (an toàn)
    BEGIN
        v_user_id := COALESCE(current_setting('audit.user_id', true), '0')::INT;
    EXCEPTION WHEN OTHERS THEN
        v_user_id := 0;
    END;

    -- Lấy tên cột khóa chính
    v_pk_col := public.get_primary_key_column(TG_TABLE_NAME::TEXT);

    -- Chỉ thực hiện nếu bảng có khóa chính
    IF v_pk_col IS NOT NULL THEN
        BEGIN
            IF (TG_OP = 'INSERT') THEN
                EXECUTE format('SELECT ($1).%I::text::int', v_pk_col) USING NEW INTO v_target_id;
                INSERT INTO public.system_audit_logs (user_id, action_type, table_name, target_id, new_data)
                VALUES (v_user_id, 'INSERT', TG_TABLE_NAME, v_target_id, row_to_json(NEW));
                
            ELSIF (TG_OP = 'UPDATE') THEN
                EXECUTE format('SELECT ($1).%I::text::int', v_pk_col) USING NEW INTO v_target_id;
                INSERT INTO public.system_audit_logs (user_id, action_type, table_name, target_id, old_data, new_data)
                VALUES (v_user_id, 'UPDATE', TG_TABLE_NAME, v_target_id, row_to_json(OLD), row_to_json(NEW));
                
            ELSIF (TG_OP = 'DELETE') THEN
                EXECUTE format('SELECT ($1).%I::text::int', v_pk_col) USING OLD INTO v_target_id;
                INSERT INTO public.system_audit_logs (user_id, action_type, table_name, target_id, old_data)
                VALUES (v_user_id, 'DELETE', TG_TABLE_NAME, v_target_id, row_to_json(OLD));
            END IF;
        EXCEPTION WHEN OTHERS THEN
            -- Lỗi ghi log sẽ không chặn lệnh chính (Update/Delete) của bồ
            RAISE NOTICE 'Audit Log Error: %', SQLERRM;
        END;
    END IF;

    -- Trả về giá trị phù hợp để lệnh chính tiếp tục
    IF (TG_OP = 'DELETE') THEN RETURN OLD; ELSE RETURN NEW; END IF;
END;
$_$;


--
-- TOC entry 278 (class 1255 OID 51174)
-- Name: get_primary_key_column(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_primary_key_column(p_table_name text) RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
    pk_column TEXT;
BEGIN
    SELECT a.attname INTO pk_column
    FROM   pg_index i
    JOIN   pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
    WHERE  i.indrelid = p_table_name::regclass AND i.indisprimary;
    RETURN pk_column;
EXCEPTION WHEN OTHERS THEN
    RETURN NULL;
END;
$$;


--
-- TOC entry 240 (class 1259 OID 51175)
-- Name: ai_forecasts_forecast_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ai_forecasts_forecast_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 241 (class 1259 OID 51176)
-- Name: categories_category_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.categories_category_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- TOC entry 242 (class 1259 OID 51177)
-- Name: categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.categories (
    category_id integer DEFAULT nextval('public.categories_category_id_seq'::regclass) NOT NULL,
    category_name character varying(100) NOT NULL,
    description text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false,
    logo character varying(255) DEFAULT NULL::character varying,
    status boolean DEFAULT true
);


--
-- TOC entry 243 (class 1259 OID 51187)
-- Name: inventory_inventory_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.inventory_inventory_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 244 (class 1259 OID 51188)
-- Name: pending_imports_approval_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pending_imports_approval_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 245 (class 1259 OID 51189)
-- Name: product_variants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_variants (
    variant_id integer NOT NULL,
    product_id integer NOT NULL,
    size character varying(10) NOT NULL,
    color character varying(50) NOT NULL,
    is_deleted boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    stock integer DEFAULT 0,
    status boolean DEFAULT true,
    sku character varying(50),
    reserved_stock integer DEFAULT 0
);


--
-- TOC entry 246 (class 1259 OID 51197)
-- Name: product_variants_variant_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_variants_variant_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 247 (class 1259 OID 51198)
-- Name: product_variants_variant_id_seq1; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.product_variants ALTER COLUMN variant_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.product_variants_variant_id_seq1
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- TOC entry 248 (class 1259 OID 51199)
-- Name: products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.products (
    product_id integer NOT NULL,
    product_name character varying(150) NOT NULL,
    category_id integer NOT NULL,
    is_deleted boolean DEFAULT false,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    product_image character varying(255),
    status boolean DEFAULT true,
    image_embedding public.vector(512)
);


--
-- TOC entry 249 (class 1259 OID 51207)
-- Name: products_product_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.products_product_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 250 (class 1259 OID 51208)
-- Name: products_product_id_seq1; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.products ALTER COLUMN product_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.products_product_id_seq1
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- TOC entry 251 (class 1259 OID 51209)
-- Name: shelves; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shelves (
    shelf_id integer NOT NULL,
    shelf_name character varying(50) NOT NULL,
    total_tiers integer DEFAULT 4,
    slots_per_tier integer DEFAULT 6,
    max_capacity_per_slot integer DEFAULT 4,
    layout jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT true,
    status boolean DEFAULT true
);


--
-- TOC entry 252 (class 1259 OID 51222)
-- Name: shelves_shelf_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.shelves ALTER COLUMN shelf_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.shelves_shelf_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- TOC entry 253 (class 1259 OID 51223)
-- Name: shelves_shelf_id_seq1; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.shelves_shelf_id_seq1
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 254 (class 1259 OID 51224)
-- Name: system_audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.system_audit_logs (
    audit_id integer NOT NULL,
    user_id integer,
    action_type character varying(20),
    table_name character varying(50),
    target_id integer,
    old_data jsonb,
    new_data jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- TOC entry 255 (class 1259 OID 51230)
-- Name: system_audit_logs_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.system_audit_logs ALTER COLUMN audit_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.system_audit_logs_audit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- TOC entry 256 (class 1259 OID 51231)
-- Name: system_logs_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.system_logs_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 257 (class 1259 OID 51232)
-- Name: ticket_details; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ticket_details (
    detail_id integer NOT NULL,
    ticket_id integer NOT NULL,
    variant_id integer NOT NULL,
    quantity integer NOT NULL,
    processed_qty integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    qr_code text,
    note text,
    is_diff boolean DEFAULT false,
    CONSTRAINT ticket_details_quantity_check CHECK ((quantity > 0))
);


--
-- TOC entry 258 (class 1259 OID 51241)
-- Name: ticket_details_detail_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.ticket_details ALTER COLUMN detail_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.ticket_details_detail_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- TOC entry 259 (class 1259 OID 51242)
-- Name: ticket_import_temp; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ticket_import_temp (
    temp_id integer NOT NULL,
    ticket_id integer NOT NULL,
    variant_id integer NOT NULL,
    expected_qty integer NOT NULL,
    actual_qty integer DEFAULT 0,
    scanned_image text,
    status character varying(50) DEFAULT 'PENDING'::character varying,
    discrepancy_type character varying(20) DEFAULT 'MATCH'::character varying,
    note text,
    staff_id integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    putaway_locations jsonb,
    is_diff boolean DEFAULT false,
    qr_code text
);


--
-- TOC entry 260 (class 1259 OID 51252)
-- Name: ticket_import_temp_temp_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.ticket_import_temp ALTER COLUMN temp_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.ticket_import_temp_temp_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- TOC entry 261 (class 1259 OID 51253)
-- Name: tickets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tickets (
    ticket_id integer NOT NULL,
    ticket_code character varying(50) NOT NULL,
    ticket_type character varying(20) NOT NULL,
    status character varying(20) DEFAULT 'PENDING'::character varying,
    manager_id integer NOT NULL,
    staff_id integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    batch_code character varying(100),
    completed_at timestamp without time zone,
    is_deleted boolean DEFAULT false,
    is_diff boolean DEFAULT false,
    CONSTRAINT tickets_status_check CHECK (((status)::text = ANY (ARRAY[('PENDING'::character varying)::text, ('PROCESSING'::character varying)::text, ('PAUSED'::character varying)::text, ('COMPLETED'::character varying)::text, ('COMPLETE_DIFF'::character varying)::text]))),
    CONSTRAINT tickets_ticket_type_check CHECK (((ticket_type)::text = ANY (ARRAY[('IMPORT'::character varying)::text, ('EXPORT'::character varying)::text])))
);


--
-- TOC entry 262 (class 1259 OID 51262)
-- Name: tickets_ticket_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.tickets ALTER COLUMN ticket_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.tickets_ticket_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- TOC entry 263 (class 1259 OID 51263)
-- Name: transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transactions (
    transaction_id integer NOT NULL,
    transaction_type character varying(20) NOT NULL,
    variant_id integer NOT NULL,
    quantity integer NOT NULL,
    user_id integer,
    reference_id character varying(50),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT transactions_quantity_check CHECK ((quantity > 0)),
    CONSTRAINT transactions_transaction_type_check CHECK (((transaction_type)::text = ANY (ARRAY[('IMPORT'::character varying)::text, ('EXPORT'::character varying)::text])))
);


--
-- TOC entry 264 (class 1259 OID 51269)
-- Name: transactions_transaction_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.transactions_transaction_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 265 (class 1259 OID 51270)
-- Name: transactions_transaction_id_seq1; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.transactions ALTER COLUMN transaction_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.transactions_transaction_id_seq1
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- TOC entry 266 (class 1259 OID 51271)
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    user_id integer NOT NULL,
    password_hash character varying(255) NOT NULL,
    full_name character varying(100),
    role character varying(20) NOT NULL,
    status boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false,
    phone_number character varying(20),
    address text,
    CONSTRAINT users_role_check CHECK (((role)::text = ANY (ARRAY[('MANAGER'::character varying)::text, ('STAFF'::character varying)::text, ('ADMIN'::character varying)::text])))
);


--
-- TOC entry 267 (class 1259 OID 51280)
-- Name: users_user_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_user_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- TOC entry 5296 (class 0 OID 51177)
-- Dependencies: 242
-- Data for Name: categories; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.categories (category_id, category_name, description, created_by, created_at, is_deleted, logo, status) VALUES (4, 'Vans', 'Vans brand shoes', 138222, '2026-03-31 13:12:46.232382', false, 'logo_van.jpg', true);
INSERT INTO public.categories (category_id, category_name, description, created_by, created_at, is_deleted, logo, status) VALUES (5, 'Converse', 'Converse brand shoes', 138222, '2026-03-31 13:12:46.232382', false, 'logo_converse.jpg', true);
INSERT INTO public.categories (category_id, category_name, description, created_by, created_at, is_deleted, logo, status) VALUES (11, 'Puma', NULL, 138222, '2026-03-31 13:12:46.232382', false, 'logo_puma.jpg', true);
INSERT INTO public.categories (category_id, category_name, description, created_by, created_at, is_deleted, logo, status) VALUES (3, 'Jordan', 'Jordan brand shoes', 138222, '2026-03-31 13:12:46.232382', false, 'logo_jordan.jpg', true);
INSERT INTO public.categories (category_id, category_name, description, created_by, created_at, is_deleted, logo, status) VALUES (2, 'Adidas', 'Adidas brand shoes', 138222, '2026-03-31 13:12:46.232382', false, 'logo_adidas.jpg', true);
INSERT INTO public.categories (category_id, category_name, description, created_by, created_at, is_deleted, logo, status) VALUES (17, 'brand_test', NULL, 138222, '2026-05-23 00:50:59.71931', true, 'brand_test_1780349251.jpg', false);
INSERT INTO public.categories (category_id, category_name, description, created_by, created_at, is_deleted, logo, status) VALUES (1, 'Nike', 'Nike brand shoes', 138222, '2026-03-31 13:12:46.232382', false, 'nike_1780349655.jpg', true);


--
-- TOC entry 5299 (class 0 OID 51189)
-- Dependencies: 245
-- Data for Name: product_variants; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (17, 3, '42', 'RED', false, '2026-05-28 01:34:20.924002', 11, true, 'AD-ASO-RED-42', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (10, 5, '41', 'RED', false, '2026-05-28 01:34:20.924002', 2, true, 'JO-J1RHOPB-RED-41', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (28, 11, '40', 'BLACK/WHITE', false, '2026-05-28 01:39:01.919894', 2, true, 'VA-VCSC-BLA-40', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (16, 3, '41', 'BLUE', false, '2026-05-28 01:34:20.924002', 6, true, 'AD-ASO-BLU-41', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (13, 4, '39', 'BLACK', true, '2026-05-28 01:34:20.924002', 2, false, 'AD-ASV-BLA-39', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (23, 1, '40', 'BLACK', false, '2026-05-28 01:34:20.924002', 4, true, 'NI-NAF1-BLA-40', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (22, 1, '41', 'RED', false, '2026-05-28 01:34:20.924002', 6, true, 'NI-NAF1-RED-41', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (4, 9, '42', 'WHITE', false, '2026-05-28 01:34:20.924002', 4, true, 'CO-CTAS-WHI-42', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (19, 2, '42', 'BLACK', false, '2026-05-28 01:34:20.924002', 8, true, 'NI-NBPL-BLA-42', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (8, 6, '42', 'BLACK', false, '2026-05-28 01:34:20.924002', 7, true, 'JO-J1RLOSTSBP-BLA-42', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (18, 3, '39', 'RED', false, '2026-05-28 01:34:20.924002', 2, true, 'AD-ASO-RED-39', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (6, 9, '40', 'RED', false, '2026-05-28 01:34:20.924002', 3, true, 'CO-CTAS-RED-40', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (11, 5, '39', 'BLACK', false, '2026-05-28 01:34:20.924002', 4, true, 'JO-J1RHOPB-BLA-39', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (24, 1, '39', 'BLACK', false, '2026-05-28 01:34:20.924002', 1, true, 'NI-NAF1-BLA-39', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (2, 7, '40', 'RED', false, '2026-05-28 01:34:20.924002', 9, true, 'VA-VOSBAW-RED-40', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (1, 7, '39', 'RED', false, '2026-05-28 01:34:20.924002', 4, true, 'VA-VOSBAW-RED-39', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (15, 4, '41', 'WHITE', false, '2026-05-28 01:34:20.924002', 3, true, 'AD-ASV-WHI-41', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (21, 2, '41', 'RED', false, '2026-05-28 01:34:20.924002', 6, true, 'NI-NBPL-RED-41', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (5, 9, '39', 'BLACK', false, '2026-05-28 01:34:20.924002', 8, true, 'CO-CTAS-BLA-39', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (9, 6, '42', 'WHITE', false, '2026-05-28 01:34:20.924002', 1, true, 'JO-J1RLOSTSBP-WHI-42', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (25, 10, '39', 'BLACK', true, '2026-05-28 01:34:20.924002', 5, false, 'BR-TS-BLA-39', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (26, 10, '41', 'RED', true, '2026-05-28 01:34:20.924002', 10, false, 'BR-TS-RED-41', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (27, 10, '41', 'BLACK', true, '2026-05-28 01:34:20.924002', 6, false, 'BR-TS-BLA-41', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (12, 5, '41', 'BLACK', false, '2026-05-28 01:34:20.924002', 10, true, 'JO-J1RHOPB-BLA-41', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (20, 2, '40', 'BLUE', false, '2026-05-28 01:34:20.924002', 10, true, 'NI-NBPL-BLU-40', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (3, 7, '41', 'BLACK', false, '2026-05-28 01:34:20.924002', 14, true, 'VA-VOSBAW-BLA-41', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (14, 4, '40', 'BLACK', false, '2026-05-28 01:34:20.924002', 20, true, 'AD-ASV-BLA-40', 0);
INSERT INTO public.product_variants (variant_id, product_id, size, color, is_deleted, created_at, stock, status, sku, reserved_stock) VALUES (7, 6, '40', 'BLACK', false, '2026-05-28 01:34:20.924002', 9, true, 'JO-J1RLOSTSBP-BLA-40', 0);


--
-- TOC entry 5302 (class 0 OID 51199)
-- Dependencies: 248
-- Data for Name: products; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.products (product_id, product_name, category_id, is_deleted, created_by, created_at, product_image, status, image_embedding) VALUES (1, 'Nike Air Force 1', 1, false, 138222, '2026-05-07 21:24:36.293413', '1778163876_master_69fca0a447cdb.jpg', true, '[-0.30663544,0.08887126,0.29166284,-0.08957319,-0.39889795,0.29056317,-0.28958696,-0.026566334,0.30917203,0.020995252,-0.20172487,-0.13993666,-0.8995106,-0.22116522,-0.0451948,-0.24053231,0.84116906,0.041031756,0.043477662,-0.18873648,-0.41954154,-0.116871625,0.041044198,-0.4147766,0.061530482,0.18470605,0.31499964,0.03737785,0.2815166,-0.18440789,-0.17273659,-0.67854536,0.19550322,0.29747403,-0.13481912,0.024450883,0.22184645,0.3418899,-0.33313724,1.5364479,-0.2495675,-0.27331364,0.3051238,-0.115370885,0.64043784,0.16326669,0.15861599,-0.253103,0.31228402,-0.16557063,0.34613317,0.378611,0.34009507,-0.11045795,-0.22101071,0.16528061,-0.11387597,0.4091586,-0.4722082,0.42437494,0.5536777,-0.10935028,0.55733407,-0.09697351,-0.046321608,0.0119341565,0.215231,-0.25617093,-0.5269496,0.077928625,-0.5474339,-0.11204634,0.0615096,-0.2242813,-0.09269776,0.2852967,-0.14202623,-0.30743256,-0.013163293,-0.2004099,-0.20273052,0.10738744,0.055368904,-0.19695042,0.13907567,0.52682483,0.45046765,0.04114177,0.2955619,-0.22561166,-0.1901548,0.4458961,-4.7435923,0.12587778,-0.0317826,0.24445164,-0.15185615,0.075476795,-0.7025863,0.30957687,-0.2598154,-0.3382106,0.5361647,-0.03381076,-0.42846513,0.5491815,-0.6193546,-0.2808621,-0.21793856,0.43661895,-0.1223734,0.55773926,0.0026754907,-0.1538344,-0.5370786,0.04982354,-0.07466349,-0.15593323,0.27881098,-0.26010358,0.33344927,0.3677626,0.20487182,0.07901362,0.3155053,-0.56679726,-0.439564,-0.3520398,0.42563853,0.31468868,0.11738425,-0.0050501684,-0.07982816,0.73966813,0.050091334,0.22656977,-0.010977759,0.16453414,0.02830689,0.0046560513,-0.14454567,-0.12380937,-0.39699355,0.95801723,0.18159989,0.16923596,0.006300671,-0.093343504,0.6486325,0.12350967,0.31193238,0.33455458,1.1483134,0.050811425,0.22354932,-0.3701848,0.32425472,0.27770162,-0.06747301,0.31003764,-0.120819144,-0.4756313,0.04900479,0.062476695,0.17193979,0.17929417,0.3908754,-0.46300745,0.13306288,-0.09584938,-0.69080275,-0.46656317,0.04183106,0.101160735,0.02233436,-0.3380323,0.24419692,0.014391565,0.17446446,-0.36126766,0.8242885,0.081839286,0.13141444,0.41556895,-0.005050434,0.26442498,-0.16212967,0.5884816,-0.058764223,0.37401032,-0.34534827,0.025091356,0.20282736,0.23206015,0.85096025,0.36055696,0.10884258,-0.3286393,-0.41453907,0.42400226,-0.09616997,-0.23815766,0.4839264,-0.22129786,-0.2864831,0.1162426,-0.70576775,-0.42353258,-0.08831842,0.2071881,-0.027622197,0.66423804,0.04778348,0.041609645,-0.35785347,0.06433609,0.21314059,0.080957286,0.19835582,0.31157893,-0.30375198,-1.1514884,0.61721355,-1.9367784e-05,0.42372155,-0.2406516,0.37296277,0.16041774,0.07948515,0.027256737,-0.04888351,-0.20488873,0.11495143,0.6102277,0.063215956,0.016885795,-0.40906632,-0.21426228,0.15197146,-0.08155187,0.09712088,-0.26422018,0.49329692,0.035440173,-0.16371657,-0.32677948,-0.3966837,0.25434566,0.034979813,-0.21094792,-0.1543896,0.5118471,0.6389595,-0.6300546,0.15320025,0.17706898,0.088159196,0.3414944,-1.1639326,0.21036743,0.0031259619,0.3993936,-0.3615886,-0.16345815,-0.0068735788,0.13221171,-0.20469704,0.3147503,0.43675476,-0.33371907,-0.42681277,0.05757226,0.020054877,-0.19013268,0.21317114,0.13873942,-0.44281146,-0.51771903,-0.21065868,-0.14054587,0.6101006,0.24657717,-0.19352952,-0.3315666,-0.12210783,-0.33691403,1.7290064,-0.13504618,-0.26396313,0.11463795,-0.04853742,0.12949087,-0.32587823,0.3997402,-0.23684148,0.047296003,-0.013183011,-0.36931533,0.07694762,0.22389568,-0.3752244,0.21920666,-0.050181746,-0.33485132,0.24718119,-0.10718571,-0.42839018,0.32164627,0.27499595,-0.5999048,-0.15186243,0.25452352,0.7382937,0.08083542,0.13318394,-0.22866912,0.4491089,0.17519207,-0.03425295,0.088235825,-0.45922336,1.4977763,-0.106241085,-0.4782758,0.32337686,0.20681025,0.19197403,0.09358944,0.33668795,-0.31806204,-0.10793795,0.1299435,0.013579822,0.08358976,0.08198255,0.17475335,-0.2768778,0.014483084,-0.4026014,0.57021385,-0.44972935,-0.20406596,-0.067546174,0.7175743,0.5407734,-0.28474838,-0.08141313,-0.25549415,-0.102129474,-0.11642353,-0.054721393,0.19534965,-0.42828655,-0.24703635,-0.7004284,0.4809147,0.08767948,0.13205346,0.17937031,-0.0035783877,0.46260688,-0.1695134,-0.178696,-0.68922865,0.15963976,0.14166799,-0.088801615,0.14185688,0.034996614,-0.11045962,-0.00082085305,0.28464773,0.43420935,-0.0500794,-0.13326012,-0.3197949,0.14891616,0.012565899,-0.20712738,0.06037318,0.106721684,0.101691976,-0.41595215,0.0005999962,-0.006174567,-0.18917951,0.10713316,0.21468493,0.16778998,0.22462301,-0.63453776,-0.37322557,-0.14108445,-0.5370589,-0.5862822,0.19897784,0.07093409,-0.00065130927,0.027769994,0.44408977,0.12656336,-0.063584834,0.9616906,-0.95189065,-0.023708915,-0.5418728,-0.22507662,0.5628494,-0.27408376,-0.42571774,0.25041404,-0.10062085,0.6549125,-0.063237675,-0.52309126,0.3876024,-0.3331277,0.21029606,-0.26238874,0.004442993,0.35799536,0.22291912,0.6082999,0.81682134,-0.14623386,0.08107864,0.5181741,-0.11933821,-1.9983904,-0.17154868,-0.394534,-0.18249369,1.2715353,-0.075819016,-0.60879606,0.017123036,-0.5908288,0.5392831,0.92369753,0.24384159,-0.03789321,-0.2562589,0.15924406,0.13812639,0.026719905,-0.48852438,-0.23179097,-0.006198137,-0.19143704,-0.11970117,-0.59355474,0.52283305,0.2963585,0.24381576,-0.41669187,0.05854187,-0.24576083,-0.08690144,-0.075599164,-0.36659977,0.08637009,0.3211502,0.042970385,0.14613144,0.40415895,-0.070249796,0.2911114,0.063479014,-0.06562245,-0.14727402,-0.13778558,0.07774856,0.04142934,-0.5383445,0.5617908,-0.53024423,-0.077425934,0.1249454,-0.28575143,0.24150474,0.38314936,-0.38149384,0.4469106,-0.37050366,-0.12610993,0.18656951,0.493098,-0.07096352,-0.33473673,-0.69502616,0.33921242,-0.5957482,0.30616048,-0.16271602,-0.48132658,-0.50439286,0.27935773,-0.020025728,0.037826963,0.12279563,-0.31737235,0.095659226,-0.17509939,0.12876275,-0.21155447,-0.41960087,-0.5674651,0.2502203,0.12501295,0.101590425,0.2821365,0.47678792]');
INSERT INTO public.products (product_id, product_name, category_id, is_deleted, created_by, created_at, product_image, status, image_embedding) VALUES (2, 'Nike Blazer Phantom Low', 1, false, 138222, '2026-05-07 21:27:34.675626', '1778164054_master_69fca156a4f8e.jpg', true, '[-0.4889849,-0.18724783,0.21389934,0.0004404001,-0.2714748,0.30622584,-0.39725223,-0.15492608,-0.0054816846,-0.346616,-0.15315081,0.23788366,-0.6204264,0.078675054,-0.23803222,-0.16854846,0.88528067,0.18264349,-0.15871108,-0.016676582,-0.496351,-0.09787481,-0.09576392,-0.46501052,0.0656169,0.09043497,0.18545717,0.12042335,0.2661814,-0.23864733,-0.13928899,-0.3931738,0.23861071,0.17502128,-0.2834129,0.16325565,0.4704847,0.27159178,-0.14589006,1.6367792,-0.12853116,-0.379708,0.027079215,-0.13856038,0.38719368,-0.53759646,0.14528573,0.13155617,0.4229679,-0.33936545,0.34861177,0.3461467,0.21653658,0.114511155,-0.20455937,0.036996625,0.31482363,0.4623577,-0.29900083,0.44538566,0.81989735,0.28912383,0.47336468,0.25525814,-0.2277364,-0.27661455,0.22099763,0.25703287,-0.46165606,0.015447289,-0.4644573,-0.3629842,-0.18133458,-0.111251965,0.18356586,0.063062064,-0.0028091557,-0.3368603,-0.20223916,-0.44566134,-0.2882869,-0.25902343,0.087939486,-0.09437299,0.11807198,0.6164672,0.26080894,-0.1342465,0.01218608,0.03240911,0.35040244,0.4911212,-4.591319,0.5417849,-0.1136505,0.0071129943,0.07343787,-0.29922506,-0.74594665,0.2805005,-0.19644368,-0.62881017,0.37806848,0.06925506,-0.21405348,0.3043722,-0.5167486,-0.041490395,-0.3134249,0.27388546,-0.29597217,0.3951572,0.22821699,0.13064103,-0.46044365,0.07256231,0.1977097,-0.37722674,0.3556241,-0.43127072,0.2715271,0.33575654,0.120828636,0.27657273,0.34095618,-0.7806789,-0.124995135,-0.23606922,0.4023933,0.012181515,0.14853656,-0.25224093,-0.014557701,0.726736,-0.12507534,0.3127075,-0.22161077,0.08240213,-0.17335556,0.06278572,-0.22530833,-0.26099074,-0.08752742,0.98598903,0.02642839,-0.11874798,-0.091543324,0.29427803,0.60488456,0.06989833,0.12430484,0.37096256,0.88331723,-0.09015576,-0.09081268,0.018931188,0.4697835,0.2336915,-0.06025384,0.33564952,-0.2240058,0.009306874,0.40201738,0.22139622,-0.010318249,-0.048091404,0.39424694,-0.46751657,0.30043048,0.028727174,-0.41177818,-0.23777716,0.1910387,0.07275914,-0.14818522,-0.43955025,0.13667762,0.007256572,0.2584579,-0.44848812,1.0368576,-0.4639226,-0.08716108,0.107767224,-0.21609686,-0.055679254,-0.025120571,0.7495934,0.10840113,0.18754776,0.2893035,-0.11718553,0.4582597,0.11262426,0.7550224,0.24962646,0.03238325,-0.38294512,-0.2836384,0.26874182,-0.25691852,-0.25955215,0.49749866,-0.035578348,-0.25149572,0.05683896,-0.73752016,-0.41020128,0.072657876,0.36759916,-0.31631723,0.55428,0.44160408,-0.3083786,-0.14024171,-0.049811568,0.014248472,-0.06386182,0.40369397,-0.0016357531,-0.35835007,-0.96267956,0.6300742,0.04752585,0.72773826,-0.40883383,0.35571817,0.1264635,0.16825277,0.071272336,-0.09860782,-0.55411,0.17701544,0.5269718,0.47962856,-0.17332314,-0.13829714,-0.21339679,0.08809111,0.098390274,0.33647016,0.29427502,0.63404614,-0.1770317,-0.10072037,-0.19960926,-0.04338568,0.34623784,-0.08836471,-0.472093,0.013398631,0.39294004,0.4464227,-0.5403147,0.41230404,0.35328966,0.01455703,0.24640393,-1.0959729,-0.051808655,-0.0048233327,0.26141763,-0.29529038,0.13239633,-0.030781308,0.35605842,-0.22554621,0.572035,0.5940803,-0.69884783,-0.3751408,-0.09756981,-0.2817315,-0.14209637,0.099843755,0.11331586,-0.025825113,-0.3883557,-0.17196062,-0.121098675,0.36915168,0.12573111,-0.5543391,-0.04988288,-0.026506264,-0.22059989,2.0680323,-0.11172903,-0.092518136,0.22508372,0.038723454,0.32802585,-0.46572256,0.5164849,-0.20464604,-0.054340925,0.025835577,-0.2532865,-0.24169786,0.05138149,-0.21407652,0.25738758,-0.12099631,-0.6040926,-0.07452907,0.06890197,-0.12332752,0.113105334,-0.085127965,-0.4350999,-0.279931,0.16149399,0.7239809,-0.1064867,0.01711973,-0.1359786,0.25887597,0.08782417,-0.18103561,0.04367061,-0.36252326,1.4415439,-0.05588261,-0.5495822,0.11663952,0.12943444,0.08741441,-0.23621573,0.10622073,-0.38455355,-0.12126066,0.18567275,0.08421917,-0.015496977,0.33677635,0.10175976,0.008268866,-0.002031941,-0.23733021,0.37346694,-0.39916125,-0.08440077,0.045388646,0.626016,0.6811413,-0.46884945,-0.022577457,-0.122430034,-0.03608006,-0.09522555,-0.25050628,0.40004262,0.12455343,-0.14206977,-0.34965894,0.4944359,0.06836135,0.38346013,-0.038213093,-0.09581935,0.2766456,-0.10293586,-0.058098204,-0.35963956,0.2306793,-0.016005732,-0.2005225,0.48532668,0.26142636,-0.12534323,0.117913865,0.78064376,0.5770618,-0.2232041,-0.28097725,-0.08807325,0.3954425,0.20413606,-0.30406204,0.076074906,0.30213258,0.10354657,-0.2849871,-0.4454595,0.16012049,0.20934662,0.26366872,-0.041795783,-0.048353128,0.7584499,-0.5062773,-0.31910634,0.002202684,-0.42925844,-0.6956705,0.23981763,0.037651323,-0.63562304,0.3612423,0.5015082,0.12987038,0.02857393,0.72966015,-0.8484155,-0.08088897,-0.43671885,0.09093132,0.31251067,-0.1733336,-0.4635547,0.45022517,0.13951063,0.10230051,-0.095042296,-0.76751757,0.4488951,0.015068196,0.683669,-0.22595142,-0.22794604,0.5664798,0.2549081,0.60614496,0.42965737,-0.21457379,0.17767552,0.2354359,-0.35126546,-1.8547593,-0.34702381,-0.47556335,-0.27239358,1.551831,-0.04868542,-0.40799424,0.102099776,-0.18389437,0.4753639,0.41633537,0.119466394,-0.25723392,0.07319318,-0.02508463,0.1657212,-0.15434322,-0.45236635,-0.15199766,-0.16327055,-0.20359132,-0.28462714,-0.20796785,0.33327824,0.2400035,0.2219084,-0.31381592,0.22247492,-0.31666806,-0.06777653,-0.14013374,-0.1548206,0.18034801,0.3093689,0.0072387364,0.28300607,0.41820803,0.06836429,0.26859623,-0.045403965,-0.14021233,-0.50541157,-0.1443752,-0.17721933,0.1551053,-0.68923473,0.3192291,-0.12778834,-0.1409894,0.16865137,0.16553074,0.07244243,0.07540051,-0.28266498,0.49847513,-0.39071685,-0.18124823,0.656852,0.3151028,-0.21794382,-0.31208086,-0.7859436,0.507222,-0.27001053,0.13074411,-0.4285873,-0.567977,-0.2730055,0.11627899,-0.22882755,-0.21602446,-0.33919644,-0.36721164,0.3507973,-0.10667329,0.06563279,-0.2576481,-0.28930634,-0.6793392,0.5123168,0.106447965,0.14394371,0.39812484,0.39059392]');
INSERT INTO public.products (product_id, product_name, category_id, is_deleted, created_by, created_at, product_image, status, image_embedding) VALUES (3, 'Adidas Samba OG', 2, false, 138222, '2026-05-09 23:08:35.419616', '1778342915_master_69ff5c0366755.jpg', true, '[-0.054030485,-0.2538141,-0.029325921,-0.13595727,-0.2305667,0.24167968,0.06775704,0.19819391,0.25675797,-0.28251988,-0.077200934,-0.34797314,-0.54002833,0.11375689,-0.4305719,0.1714541,1.4761091,-0.25100347,-0.055421993,0.1662703,-0.05561811,-0.124045424,0.07325434,-0.21358426,-0.15881029,-0.0016577635,-0.06602248,-0.13848361,0.38681304,-0.12194273,-0.42576692,-0.0134800915,-0.47465438,-0.12627654,-0.3356065,0.09909165,0.30360764,0.25438032,-0.039427128,1.6768215,-0.50174713,-0.72785306,0.5819592,0.23994586,0.13571954,-0.611031,0.08694782,-0.21311864,0.29029673,-0.31382993,0.021780428,0.5229753,0.14895804,-0.050986033,-0.3219182,0.003752824,0.42390522,0.3186578,-0.74258256,-0.13414468,1.086098,-0.30483058,0.20319025,-0.30174682,0.0061796214,-0.024829889,0.56498444,0.09568525,0.0013312548,-0.00744203,-0.50259405,-0.17085613,-0.18965101,-0.18839969,0.06491327,-0.39562216,-0.020929458,-0.30370915,-0.038912125,-0.47694126,-0.14474544,-0.449528,0.16496755,-0.08516411,0.17156084,0.63908327,-0.04073047,-0.3260619,0.16879934,-0.31703624,0.0059140967,0.074929036,-5.003546,0.5927098,0.08136852,0.04819201,-0.051747475,-0.14617062,-1.0922982,0.17357446,-0.18113856,-0.3660497,0.14478748,0.12086575,-0.3849535,0.1710648,-1.0286212,-0.2285256,-0.5868941,0.060744595,-0.08880036,0.63036245,0.26415694,-0.10285796,-0.43608198,0.011627995,-0.19623974,-0.52371943,0.4331974,-0.056032248,0.2828893,0.066965975,-0.09065986,0.22787042,0.2812684,-0.051713005,-0.23507765,-0.15682942,0.20212425,0.084899634,-0.12354247,0.077854216,-0.10139452,0.75960124,0.07194014,-0.27280945,-0.18002582,0.23508035,-0.12991238,0.16906813,-0.3372914,-0.23626246,-0.03045963,0.8143722,-0.00021097343,-0.23712401,-0.2432026,-0.010700069,0.34949192,0.16045287,0.49412447,-0.17537132,0.5728091,0.09463329,-0.18021332,-0.24322069,0.09786788,0.3014381,-0.29424104,0.4133093,-0.34593186,0.03907889,-0.0781425,0.37334508,0.23892339,0.2827278,-0.051767975,-0.15205431,-0.09244093,-0.17276241,-0.52960145,-0.40569708,-0.033419378,0.024564557,-0.25775954,0.019084806,0.94612086,0.08275741,-0.07806933,0.07214464,0.72338915,-0.515399,0.23805849,0.31063396,0.04292377,0.5596531,-0.29580027,0.4954333,-0.27855316,0.033836417,-0.15287337,0.16142179,0.14463226,-0.110817716,0.954445,0.090774216,0.24946369,0.14799407,-0.43666428,-0.196778,-0.11368829,-0.1553067,0.48137376,-0.028331235,-0.32002127,0.026314432,-0.63352597,-0.24253498,-0.11067073,0.26625532,-0.25364652,0.6714874,0.18525665,-0.42635795,-0.21294746,0.1742051,0.2105198,-0.41374442,-0.2355202,0.08203121,-0.31610984,-1.0916674,-0.046265908,0.29057744,0.46610686,0.109053314,0.4235549,-0.26484922,0.19837633,0.41810456,-0.008667123,-0.29143274,0.13560335,0.44335133,0.33321375,0.005917754,-0.23104723,-0.14105043,0.058960654,0.12559757,0.2434402,-0.187395,0.1375837,-0.27871457,0.25006694,-0.3450592,0.2634747,0.05932878,0.055703163,-0.23002946,-0.19732687,0.34970677,0.2803522,-0.0055211093,0.17000815,-0.114756554,-0.4820166,-0.11264835,-1.5037934,-0.5131401,-0.05849253,0.26054215,-0.2984643,0.79890394,0.03875225,0.21458514,-0.33447334,0.14683354,0.1849483,-0.2156917,-0.40233746,0.27551666,0.025243929,0.016801544,0.42961204,0.17035626,0.053129293,-0.275865,-0.35888866,-0.08790205,0.19035144,0.44878784,-0.6403622,0.06870277,0.007948957,-0.4951402,2.281528,-0.24019189,-0.17344837,0.0690248,-0.20092449,0.16119274,-0.40620446,0.3188008,-0.18328314,0.009944137,0.3539539,-0.33681726,0.4217868,0.32439637,-0.107742645,0.46124363,0.05228832,0.23953134,0.4207845,0.02470519,0.11017759,0.28579557,-0.047150332,-0.5531548,-0.3212899,0.14199866,0.75667757,0.15441999,0.13934042,-0.22278625,0.05163917,0.2919586,-0.57209235,-0.048466776,0.040487144,1.0652064,-0.007507328,-0.20943931,0.80145943,-0.30599877,0.15308826,-0.05585612,0.32024568,-0.3188079,-0.064594395,0.2941572,0.0059130955,-0.23861304,0.17761147,-0.29220665,-0.10497944,-0.04436088,0.52971214,0.5299141,-0.4018995,0.084170274,-0.1561219,0.5141363,0.6522015,-0.3744304,0.0035821162,-0.1916472,0.29982156,-0.1300889,-0.25003844,0.28288034,-0.027329864,-0.49097678,-0.44512564,0.3306591,0.04056525,0.32340366,-0.24395473,-0.113422215,0.39552465,-0.19699228,-0.25016823,-0.7973154,0.18989772,0.15190892,0.09870146,0.7954563,0.13096109,-0.018167304,-0.089674115,0.2034829,0.54264355,-0.09872267,0.04495553,-0.16925846,0.6642539,0.29779404,-0.13308446,0.17714,0.11033834,0.2696018,-0.14601249,-0.3401171,0.20491365,-0.13806362,0.6399451,0.2526354,-0.24631794,0.90727377,-1.366475,-0.17645231,-0.2523744,-0.13952951,-0.10394463,0.024533512,0.23713666,-0.3631892,0.49241963,0.36578962,0.05532986,-0.15797661,1.3161741,-0.34502393,0.17774633,-0.04273804,0.13880004,0.73242694,-0.66777855,-0.29246515,0.14513966,-0.50728583,0.6157938,0.008609436,-0.68901247,0.49740246,0.2727331,0.24954492,-0.17356735,-0.18951322,0.45840275,0.87388116,0.78836435,0.34354454,-0.04607429,0.082044,-0.029861962,0.28324586,-0.8799864,-0.14786191,0.15673871,-0.45617822,0.9810673,-0.26489177,-0.3328374,-0.14916006,-0.36739904,0.33488125,0.46075386,0.30443895,-0.1847201,-0.29762548,0.23884718,0.099234074,-0.19231287,-0.53094774,-0.45320663,-0.32060808,0.13111624,-0.33965376,0.030931577,0.6839883,0.6910752,0.024518209,-0.43202612,-0.019543257,-0.38723058,0.111563876,-0.058975782,0.35299996,-0.26605806,0.5093559,-0.04025717,0.27881116,0.8137909,0.19099852,0.24762137,-0.033801675,-0.3998751,-0.15874606,-0.2250489,-0.15308936,0.25262192,-0.8362213,0.025501126,0.02107371,-0.51556224,0.3529765,0.009647246,0.078441784,-0.08367266,-0.67633814,0.059458733,-0.07531266,-0.024761891,0.3452023,0.12956333,0.06018868,0.1836408,-0.2379705,0.44004446,-0.2964749,-0.075170994,-0.27693135,-0.38607496,-0.46541715,-0.27402562,0.09083942,0.08442659,-0.14654532,0.347288,0.3191878,-0.12007591,0.14912283,-0.28049982,-0.34240827,-0.3763358,0.7316209,0.13795486,-0.15787731,0.33948752,0.46578655]');
INSERT INTO public.products (product_id, product_name, category_id, is_deleted, created_by, created_at, product_image, status, image_embedding) VALUES (4, 'Adidas Superstar Vintage', 2, false, 138222, '2026-05-09 23:09:19.880391', '1778342959_master_69ff5c2fd6f31.jpg', true, '[-0.27300525,-0.34314233,0.098704636,-0.21279515,-0.47992897,0.2749857,0.014315516,-0.27323452,0.22309336,-0.09067864,0.16259871,-0.09227045,-0.11158086,-0.015859056,-0.5183882,0.32916784,1.2305516,-0.27332655,0.21461135,0.12596759,-0.37186047,-0.09000219,0.12596354,-0.38184553,0.03147479,-0.19804235,-0.24864735,0.1689238,0.29489067,0.0053060055,-0.36035147,-0.033034205,-0.10105283,0.07175881,-0.47017157,0.016251031,0.20164561,0.3409214,-0.1841986,1.7882298,-0.5782688,-0.6614777,0.36273316,0.07747243,-0.11920554,-0.80648047,-0.055946585,0.07554751,0.23863928,-0.11786729,-0.121166766,0.15976311,0.03304899,-0.03534634,-0.0033383928,-0.3653318,0.59786075,0.051277738,-0.7691634,-0.38280493,1.0229231,0.019687591,0.37681574,-0.3657359,-0.17082402,-0.23763019,0.40631932,0.011999361,-0.057249937,-0.122694016,-0.4027746,-0.29230487,0.014419736,-0.07951517,0.46982813,-0.36707953,-0.050861757,-0.52812546,-0.06782305,-0.4916137,-0.20959726,-0.37466198,-0.028028306,-0.33944175,-0.04144699,0.94144285,0.5484484,-0.19757783,-0.4012922,-0.46994066,0.39386466,-0.04953763,-5.3224435,0.44792438,0.1459615,-0.24910232,-0.10233712,-0.14582607,-0.89541554,0.32281947,-0.25903618,-0.55692023,0.16408163,-0.0390968,-0.0053473124,0.18665747,-0.30150533,-0.103804216,-0.6459298,0.011963457,0.13322262,0.20838994,0.18758784,-0.010022769,-0.23269409,0.2363995,-0.015457554,-0.31975397,0.092196375,-0.10777529,0.05316523,0.21274695,-0.14321053,-0.06730688,0.16497998,0.07277184,-0.4148729,-0.21319786,-0.0795882,0.19163346,-0.022712633,-0.07104464,-0.5102415,0.75374025,0.09779998,-0.16634445,0.041584328,0.5511827,-0.017996708,0.06083872,-0.26548144,-0.34334192,-0.23612985,0.75384974,-0.37932196,0.0597438,-0.33290684,0.036041036,0.16335043,0.13908772,0.61862963,-0.21323188,1.1058697,-0.209161,-0.0052903164,-0.011799027,0.2025281,0.71174324,-0.022388307,0.31787658,-0.54699916,0.22368611,0.02965378,0.21340418,0.08609117,0.2687376,0.18286002,-0.23489653,0.08561989,0.078202985,-0.5556317,-0.4379251,0.07945579,-0.0093776705,-0.3051999,-0.19878632,1.2104511,0.25976545,0.14606091,0.16229442,0.6860123,-0.3120908,0.1487184,0.503692,-0.02011977,0.37452942,-0.32956982,0.36699194,-0.23979829,-0.12342374,-0.37793183,0.07193133,0.020552704,-0.09158316,0.8935527,0.285992,0.15394872,-0.06377829,-0.2744303,-0.31695077,0.018262584,-0.5058785,0.57261336,0.09039475,-0.08084768,-0.079564326,-0.52386385,-0.18574211,0.118165284,0.07495113,0.09433491,0.63319117,0.1850098,-0.22781019,-0.16547619,0.3627813,0.0719223,-0.17285758,0.31386027,0.12775221,-0.1221401,-0.8632289,0.20113271,0.061574005,0.650342,0.14537755,0.18066004,-0.22212303,0.19272053,0.52103615,0.20045227,-0.25580958,0.015508888,0.41504487,0.3382721,0.05974217,-0.17626436,-0.26622033,0.090236574,0.08024538,0.29712898,-0.27086535,0.40510845,-0.014115334,0.58110636,-0.17534097,0.23039056,-0.032559626,-0.19194064,-0.07874112,-0.13955991,0.43081647,0.44211072,0.17797753,0.11997571,0.22802189,0.12727559,0.005992286,-0.85466963,-0.42307135,-0.08199237,-0.041676022,-0.19059205,0.518695,-0.18610755,0.35849342,-0.14474052,0.3547619,0.37143442,-0.36183608,-0.28641546,0.21410158,0.20310868,-0.4165784,0.15244985,0.34105888,-0.018694984,-0.13497497,-0.44013828,0.093577236,0.04976147,0.13021414,-0.53745013,-0.045534592,-0.1292517,-0.5128319,2.59277,-0.39385083,-0.04180786,0.1796963,-0.008035272,0.069592625,-0.75175035,0.102352545,0.0349483,0.017381266,0.16978636,-0.06059691,0.1359532,0.5171163,-0.42113787,0.30126822,0.1778418,0.28531566,0.16229568,0.0046103364,-0.12521422,-0.156544,0.15066937,-0.30512154,-0.64019084,0.30294368,0.75129604,0.08754037,0.18488283,-0.15677406,0.2511265,0.29584527,-0.7544096,-0.18379724,-0.004715005,2.3669684,0.013753155,-0.35118276,0.7106768,-0.2126325,0.15063259,-0.20289189,0.19793728,-0.19897951,-0.56619394,0.4721586,0.27797848,-0.26250198,0.26100472,-0.42205375,-0.054778434,0.041442003,0.37754813,0.16518898,-0.21357694,0.43685365,-0.026089288,0.32077163,0.25615555,-0.13027017,-0.16567415,-0.09989475,0.074534245,0.062093183,0.058680333,-0.02022916,-0.051334057,-0.57294834,-0.43822178,0.48297134,0.0192296,0.23967202,-0.1331992,-0.114767745,0.52698016,-0.21536085,-0.11954247,-0.6753946,-0.06686981,-0.1413181,0.027390307,0.7149211,0.271295,0.37253955,-0.093067735,0.29721418,0.4980031,-0.08433302,0.12081068,0.098316245,1.0486389,0.087002955,0.14047918,0.058354378,0.3468532,0.078306906,0.03606895,-0.23990017,0.17713755,-0.20162201,0.35564741,0.34068078,-0.38342267,1.2300245,-0.5452064,-0.35338262,-0.20005372,-0.333075,-0.19893709,0.072484404,0.31045118,-0.41296145,0.3700802,0.55214494,-0.1563914,-0.05653167,0.9342733,-0.2320732,0.15751095,0.277453,0.34950426,0.8759526,-0.28659618,-0.051147576,0.3147121,-0.3719035,0.29198965,-0.0026091859,-0.46062744,0.22644538,0.30018663,0.27678892,-0.2946755,-0.1451728,0.18129405,0.89777803,0.38252506,0.4541423,-0.27572495,0.16163655,0.22388054,0.15656753,-1.357634,-0.31736454,0.08336808,-0.57598716,1.2937176,-0.18401699,-0.34252983,0.161468,-0.19295853,0.27570355,0.49321085,-0.19098948,-0.26144347,0.0058590537,0.07482244,0.22525173,-0.46017954,-0.5617922,-0.20481326,-0.21141881,-0.004287338,-0.24550012,-0.09633008,0.8319317,0.5227533,-0.046528324,-0.5552983,0.07271518,-0.33792964,0.117057204,-0.05546507,0.49363995,-0.1388758,0.53516096,-0.14482246,0.30703065,0.73656243,0.17514229,0.18834426,-0.123126626,-0.40884382,-0.19983846,-0.13363218,-0.19244829,0.22145227,-0.9723441,0.12603197,-0.13946137,-0.3712961,0.3718157,0.025815975,-0.06125821,0.12083847,-0.18048939,0.33353338,-0.4177844,-0.26429248,0.42171687,0.13703685,-0.01890673,0.08807683,-0.25384054,0.31544933,-0.32221532,-0.16644892,-0.44288194,-0.31013593,-0.3929395,-0.7213961,0.03985281,-0.12510441,-0.37695324,0.13532469,0.43233755,-0.12808467,-0.3253632,-0.29405108,-0.42346993,-0.2777208,0.55571043,0.008410834,0.41368797,0.18498507,0.5094315]');
INSERT INTO public.products (product_id, product_name, category_id, is_deleted, created_by, created_at, product_image, status, image_embedding) VALUES (5, 'Jordan 1 Retro High OG ''Patent Bred''', 3, false, 138222, '2026-05-09 23:10:13.604394', '1778343013_master_69ff5c659390f.jpg', true, '[-0.13385653,-0.045281548,0.082491264,-0.17407204,0.30467826,0.33804986,-0.097859226,-0.21074858,0.36805627,-0.14381732,0.12415229,-0.38684547,-0.86488503,-0.40637964,-0.15578535,0.23852524,0.95374006,-0.847236,0.38848594,-0.18172395,-0.5817882,-0.35220104,0.23146668,0.09588775,0.10936105,0.08057898,-0.1383192,0.32105815,-0.13823831,-0.090180546,-0.3344066,-0.29765615,0.30101028,0.30280504,-0.28625423,0.10019103,0.49981382,0.25845522,-0.029171962,1.9286587,0.28677285,-0.38129148,0.10585522,-0.06650041,1.1060251,0.8635507,0.3492706,0.18988505,-0.047758408,0.19762006,0.2757016,0.18734868,0.019770253,-0.3597663,0.18200986,0.035809994,-0.50098014,0.28032753,-0.1559615,0.44521728,0.15029712,0.17025816,0.3215939,-0.03357903,-0.36081177,-0.23992173,0.66904634,0.13308486,-0.5737242,-0.15665285,-0.058546163,-0.17638025,-0.19258496,-0.46748364,0.3977288,-0.15952796,-0.22479784,-0.16318788,0.06004507,0.03970506,0.047249645,0.16621536,-0.65856844,0.0065206327,0.025312066,0.45003912,0.21921268,-0.101240695,1.0087507,0.4393499,-0.14881402,0.65064514,-5.7094183,0.18772896,0.3583395,0.10717549,-0.11286084,-0.06419419,-0.14336908,-0.21959549,-0.059606403,0.050401676,0.09973295,0.5531907,-0.07974837,0.505817,-1.1045411,0.2624532,0.1984387,0.5332964,0.03908926,0.24852386,0.37656578,-0.22818255,-0.5927799,0.089137405,0.07580135,-0.113255724,0.04151583,-0.22422883,-0.093094856,0.697199,0.022780612,-0.0027221683,0.28850645,-0.7344179,-0.070610926,-0.28044122,-0.14721733,0.5828959,0.39255,0.3729546,-0.1984778,0.7551987,0.09705623,-0.16115482,0.121954724,0.48630694,0.21524802,-0.12133465,-0.27236363,-0.007283222,-0.42956203,0.8519187,0.0857036,0.2617069,0.043116655,-0.41916046,0.29504612,0.50875455,-0.20739613,0.02911374,1.0232991,0.007777728,0.3952993,-0.34173217,0.116723806,0.216272,0.3731426,-0.2017115,-0.19375801,-0.3723967,-0.285516,0.1928697,0.49125785,-0.17166339,0.9507182,-0.4197152,0.17039108,-0.54045045,-0.49593183,-0.19330594,-0.0074485242,0.022497207,-0.045360245,-0.8559395,-0.64696676,-0.053865857,-0.18686604,-0.20494996,0.43329632,-0.03109485,-0.20273608,-0.052549124,-0.024536142,0.44298887,0.46316573,0.3750548,-0.33262125,0.2551928,-0.7406077,0.15092659,0.41384834,0.6525118,0.41048574,-0.29087794,-0.23501033,-0.00083237886,-0.20380984,0.448694,-0.16953117,0.16733558,0.32858038,-0.4797535,-0.3834436,-0.35464624,-0.9284831,-0.15882933,-0.28330386,0.2815488,-0.21802142,-0.5302771,0.009605341,0.29602385,-0.3028381,0.10403166,0.30720955,0.10958341,0.24263859,0.3618393,-0.29454243,-0.85474014,0.6886804,0.41170374,0.5636726,-0.41451082,0.32026944,0.10880083,-0.15914656,0.07714445,-0.22874905,-0.11093674,-0.43634057,0.13138491,-0.05327841,-0.13122408,-0.02691222,-0.20095149,0.28040951,0.04124581,-0.11977963,-0.11594118,0.045838982,0.019974977,0.13580352,0.02060717,-0.22286277,-0.023574859,-0.3421656,-0.07159123,0.15875326,0.22594297,0.19911632,-0.28640437,-0.057850998,0.1433448,0.34569553,0.26660264,-1.067663,-0.012091873,-0.5089416,0.82158536,0.0009047594,-1.0221092,-0.20044693,0.14280824,-0.14712164,0.32135138,0.21511804,-0.5947648,-0.45884115,0.2801242,0.27895668,-0.07204737,-0.13897456,0.12306381,-0.036336005,-0.35342798,-0.10527163,-0.23214696,0.018198246,0.25661963,-0.22462454,-0.076323345,0.020162202,0.17535508,1.679817,0.1242888,-0.42004776,-0.24500002,0.454376,0.30497676,-0.38052723,0.68331873,-0.07061832,-0.16295104,-0.37227875,0.14930685,0.28531104,0.45557946,-0.35371768,0.18049377,-0.23636654,0.1184243,0.43101856,-0.3310021,-0.1376001,0.051661868,0.30502516,0.07804172,-0.20844859,0.3316456,0.7532463,0.31577036,0.1404283,0.22378975,0.20226492,-0.15771928,0.20097291,0.23101936,0.27540693,1.1427023,0.2726446,-0.25866148,0.1940605,0.19925755,-0.29476887,-0.036261745,0.49091473,-0.4268962,0.07396857,-0.20262283,-0.068547994,0.19435069,0.0036494508,-0.04808433,-0.50943995,-0.06383102,0.13980713,0.087433636,-0.4508603,0.05762802,0.3262905,0.47795758,0.66562104,-0.060393244,0.48940313,-0.1326133,0.06480895,0.14348006,-0.3966657,-0.19219533,-0.6108764,0.024276633,-0.30202252,0.14578623,-0.19249159,0.67005193,0.4485362,-0.096637085,0.5980921,-0.08819029,-0.19371127,-0.17426151,-0.07143615,-0.06823743,-0.11059807,1.1530414,0.04157316,0.69412214,0.31096697,0.07510564,0.5654447,-0.17515703,-0.30662662,0.19752532,0.33180678,0.3013979,0.5467788,0.02753393,0.13846132,0.2105635,0.004181491,-0.26251346,-0.03491436,-0.4472242,0.2864685,0.21040632,-0.075541794,0.5378772,0.009112619,0.29478726,0.08326883,-0.17178817,-0.23430777,0.3785979,0.55649084,-0.16895877,0.18453866,-0.29572508,0.48370168,0.17954631,0.33466804,-0.7880199,-0.16341636,0.18964642,0.25793198,0.6547184,0.5194851,-0.4065764,0.13654983,0.44119236,0.60065794,0.030471735,-0.2872589,0.09111109,-0.04630172,-0.09833943,-0.32425374,0.1590728,0.6140466,0.36797127,0.29198876,1.2785783,0.17233339,-0.17771408,0.59331423,0.005862659,-2.0873811,-0.17827019,-0.12864411,-0.16193286,0.15452525,-0.27606,-0.6767163,-0.0621484,-0.6127551,0.2501827,0.5283791,-0.15880084,0.15758292,0.32270834,0.43956274,0.010093067,0.37096867,-0.53676987,0.10842974,0.044139694,0.19012552,0.07616462,0.021755092,0.6457791,0.12683438,-0.03307036,-0.10981063,-0.06960211,-0.39658177,-0.070890225,-0.3221887,-0.027387884,-0.28781152,0.5331987,0.20013128,0.14976382,0.0010688547,-0.10066457,-0.11550805,-0.08845705,-0.05547269,-0.64247674,-0.41712767,0.23051012,0.48870602,-0.5092114,0.29693076,-0.37953746,-0.5385875,0.09725298,-0.65067315,0.09253859,0.23522715,-0.18730523,0.48214865,-0.36994353,-0.7621943,-0.14407578,0.18466726,0.021358136,-0.29855153,-0.81198853,0.330523,-0.4203232,0.09278048,-0.14189847,-0.48847324,-0.2831152,-0.13699317,-0.40737975,0.292208,-0.35108244,-0.0048425123,0.47214323,-0.0061179013,0.14040703,-0.27973515,-0.3637133,-0.5996239,0.16089848,0.2777931,0.3011136,0.1424976,-0.09068569]');
INSERT INTO public.products (product_id, product_name, category_id, is_deleted, created_by, created_at, product_image, status, image_embedding) VALUES (7, 'Vans Old Skool Black and White', 4, false, 138222, '2026-05-09 23:11:53.807914', '1778343113_master_69ff5cc9c540b.jpg', true, '[-0.47049725,-0.057613812,0.22263473,0.26707318,-0.14443268,0.51587415,-0.05597881,-0.20733461,0.20676543,-0.20642248,-0.074004315,-0.09346266,0.015831504,0.010533404,-0.48536035,0.20339683,0.7148074,0.08104973,0.23380923,-0.12922263,-0.15337482,-0.0435588,0.5134761,-0.29708636,0.32131684,0.4035793,-0.017492682,0.25017953,0.114068955,-0.2598502,0.0974738,-0.2916139,0.0041885413,0.021840531,-0.19133265,0.19234979,0.4008706,0.436172,-0.25447682,1.2151278,-0.5959651,-0.52208054,0.12414705,-0.21297212,0.104174085,-0.6198364,0.4493669,-0.1973233,0.44737855,-0.16718061,-0.2352932,0.40527156,0.25907803,-0.036871437,-0.38343138,-0.14130242,0.15302162,0.23916462,-0.2544914,0.18410048,0.8700733,-0.100500785,0.36327973,0.1488591,-0.079451844,0.19469409,0.2572514,0.032445118,0.1515351,-0.04861586,0.16847193,-0.0022341618,-0.20990919,-0.096828006,-0.24168082,-0.17055255,0.37786293,-0.66608787,-0.3188501,-0.2457525,-0.21676151,-0.30829135,-0.30951458,-0.047864057,0.1596693,0.6472042,0.76716524,-0.06671103,-0.14416039,-0.23038182,0.16005266,0.25865936,-4.861438,0.4401528,0.029151578,-0.11257218,-0.05088177,-0.39217958,-0.77679217,0.28907666,0.096500315,-0.746413,0.19719876,-0.179154,-0.35836938,0.053140864,0.018011957,0.037293315,-0.31637686,0.20618334,-0.061769433,0.19882065,0.0794791,0.020796044,-0.2678427,-0.1616946,-0.26251075,-0.3234885,0.20629163,-0.52559525,0.08791812,0.34734875,-0.38729888,0.32289904,0.17656088,-0.29025254,-0.26062515,-0.016680757,0.07130575,-0.05894245,0.01874585,0.12934245,-0.118927136,0.69771844,0.34474587,0.08187875,-0.086158514,0.3918984,-0.08485721,-0.14767216,-0.025706865,-0.13234884,-0.12358304,0.39421028,-0.27834517,0.15759432,-0.30433837,-0.5533447,0.37687117,0.20024423,0.37538323,0.14004625,1.1614655,-0.043260433,-0.12178117,-0.08592681,0.22723529,0.013859039,0.16560398,0.058885295,-0.70201993,-0.16735344,0.34163737,0.57439244,0.1278651,0.202968,0.4705088,-0.11301215,-0.18190834,-0.10863353,-0.29380846,-0.18876119,0.4695529,0.24911359,-0.21191074,-0.17588842,0.9040626,-0.09916206,0.18497941,-0.39175487,0.80334574,-0.15045625,0.16571929,0.29528782,-0.26447618,0.27436745,0.07124774,0.4615259,0.04625497,0.12074736,0.32733926,-0.21923536,0.21282434,0.113113016,0.77657074,0.13228264,-0.13611542,0.07528746,0.14485732,0.29699844,-0.36200556,-0.47375858,0.8108126,0.030629,-0.0765123,-0.087102585,-0.7467624,-0.15985286,0.1307682,0.12412866,-0.054920804,0.649407,0.47920406,-0.25502047,-0.4393779,-0.12798053,0.15647054,0.23471116,0.37495387,0.07226178,-0.22770911,-0.7504712,0.3692015,0.19946112,0.56583285,-0.30904287,0.31750444,0.29438436,0.20253894,0.3393945,-0.27686647,-0.43402454,-0.23466079,0.53534025,0.44655073,-0.35179794,-0.13217665,0.0921717,0.19907336,-0.2930618,0.06553423,0.16624463,0.2259693,0.068548925,0.312176,-0.26323187,0.16653538,0.12375638,-0.3874589,0.12988475,6.272923e-05,0.32283285,0.38563856,-0.32567126,0.021529265,0.46614772,0.4011204,0.04390373,-0.7379766,-0.34970963,-0.15578234,0.42895705,-0.067056686,-0.4439215,0.1144596,-0.050575066,-0.21033287,0.20781232,0.45202127,-0.026284356,-0.60431874,-0.18896145,0.29585502,-0.56128037,0.29480213,-0.20026426,-0.3294337,-0.2727532,-0.46822736,0.08654709,0.0055949744,0.16837555,-0.5817344,-0.36207426,-0.2476072,-0.0821883,2.6331937,0.0097288685,-0.32421532,-0.016463924,0.0743321,-0.11314963,-0.19133347,0.56201124,-0.07972629,-0.0071880836,0.074205026,-0.12206006,0.35806,0.081738986,-0.3512915,0.13454926,0.0913693,-0.016057404,0.09955421,0.13423988,0.15611753,0.1274133,-0.110147744,-0.62131065,-0.28005677,0.24012235,0.69538754,0.3830483,0.3110316,-0.18285492,0.35031447,0.45380154,-0.12377466,0.22347626,-0.1448665,2.2034872,-0.36696535,-0.7883989,0.9128388,0.05596809,0.010825012,0.25280148,0.217303,-0.32349604,-0.24202421,0.15703283,0.024079569,-0.20504108,0.015856907,0.2228889,-0.04132427,-0.17639747,-0.39774397,0.3891486,-0.61144114,-0.46390063,0.081952766,0.12713648,0.48809034,-0.04307373,-0.64367956,-0.12873387,-0.3065117,-0.09731849,-0.063186266,-0.24520876,-0.11302537,-0.23666939,-0.485879,0.46421692,0.029365242,0.5469167,0.3363031,-0.1062991,0.5337687,-0.22488427,-0.14813311,-0.54166794,0.22582763,-0.063301176,-0.0019003116,0.43732417,0.4804804,0.08861544,-0.06396562,0.12325316,0.6971581,-0.20236516,-0.109190345,-0.1530669,0.58182555,0.2864713,0.13599572,0.25813785,0.06542669,-0.015772406,-0.31744775,-0.4287684,0.09230849,-0.03659,0.50920767,0.00793618,-0.4168504,0.6128451,-0.6694082,-0.45170936,-0.030898308,-0.13137251,-0.51841193,0.20175926,0.01609264,-0.08513005,0.7476484,0.13059503,0.2538897,-0.07789424,0.57523733,-0.6285439,-0.03218738,0.01253194,-0.09206933,0.6367888,0.19633357,-0.04077813,0.2492655,-0.041193586,0.67994493,-0.07353574,-0.58573127,0.42342734,0.12038915,0.13286968,0.10847916,-0.23489618,0.60073656,0.6193523,0.23128861,0.34597445,0.0721177,0.15407205,-0.21504126,-0.25686646,-1.5394763,-0.29219612,-0.09640262,-0.12227429,1.1878873,-0.19818784,-0.29326499,-0.12950462,0.08148648,0.5177172,0.40738449,0.43868086,0.0035730153,0.30987203,0.5171017,0.18949154,0.17408188,-0.46576905,-0.19237909,-0.20146579,0.017789485,-0.2833345,-0.41021582,0.4965153,0.13911965,0.092342235,-0.7592523,0.30019566,-0.3930449,0.06282422,-0.11443613,-0.23924445,-0.040377483,0.2006722,0.018439014,0.032454442,0.025964791,0.12226592,0.26932135,-0.26319394,-0.6842368,0.13895461,-0.5138419,0.04620164,-0.056166306,-0.892064,0.36176315,-0.15116729,-0.08593673,0.41903278,0.14792557,-0.1042628,-0.03273747,-0.2589473,0.33961362,-0.12327355,-0.22607315,0.35017443,-0.027579596,-0.36734715,-0.042962454,-0.34814572,0.38384765,-0.44680977,0.039622314,-0.40778115,-0.16665594,-0.35665402,-0.06899455,0.15204047,-0.16257742,-0.09937155,-0.03731959,0.31513384,0.0013458878,0.2270279,-0.27430376,-0.2741329,-0.8233114,0.43154258,0.012047164,0.38654798,0.21305424,0.6472398]');
INSERT INTO public.products (product_id, product_name, category_id, is_deleted, created_by, created_at, product_image, status, image_embedding) VALUES (9, 'Chuck Taylor All Star', 5, false, 118999, '2026-05-24 04:39:49.345691', '1779572389_master_6a121ea55467d.jpg', true, '[0.05614227,-0.2813876,0.5053754,0.3661735,-0.23167215,0.3731275,0.10910444,0.19541147,-0.0048080813,0.1474476,-0.31596044,-0.17160194,-0.08320771,-0.11176179,-0.3943028,0.18204598,0.36813885,-0.055821057,0.3691416,0.082652986,-0.011407908,-0.025829308,0.22741725,-0.27227625,0.45680818,0.24148299,-0.09620747,0.090078145,0.104758166,-0.4346819,-0.3946535,-0.40914753,-0.159918,0.25846612,-0.12281275,0.16631837,0.23043771,0.52456266,-0.16119492,1.2176476,-0.36920968,-0.470056,0.20488447,0.058092296,0.22069778,-0.39758238,0.31438312,-0.25343472,0.41787308,0.3550395,-0.21536149,0.06680899,0.45213085,0.009626921,-0.2654794,-0.20895474,0.43243816,0.12924618,-0.23775771,0.0068590157,0.34409633,0.0066357916,0.105997145,0.22321482,-0.006878307,-0.033383563,-0.0155584235,-0.092393875,0.24149518,0.13439843,0.06808215,-0.25926453,-0.22863758,0.1507444,0.21860082,-0.12992352,0.44264725,-0.69071835,-0.085457735,-0.2742848,-0.14408562,-0.26144183,-0.3881697,-0.045498848,0.1864486,0.66102123,0.3254318,-0.34804082,-0.097574,-0.42772293,0.18316266,0.17726591,-5.5892653,0.31554306,0.021935508,-0.061676487,0.08354864,-0.08999471,-0.67647594,0.7226447,-0.045107078,-0.49383584,0.3041962,-0.09090623,-0.4354139,0.22612283,0.017052807,0.12699501,-0.2804267,0.4246358,-0.06926665,0.43568403,-0.06803765,-0.19657937,-0.16212595,-0.022806834,0.057088617,-0.29660544,-0.03788297,-0.55644536,-0.079144426,0.35971704,-0.4389929,0.20724207,0.09669551,-0.3301872,0.004800951,-0.09094395,0.28883213,-0.22183555,0.081029005,0.24543135,-0.16204105,0.7875454,0.3438666,0.13487306,0.17377356,0.5632569,-0.13945116,0.032147527,0.120552,-0.2478157,-0.32338497,0.26463157,-0.262198,0.341876,-0.28951284,-0.16246639,0.2208331,0.18567502,0.29986557,0.40219814,1.0718435,-0.14777797,-0.015790187,-0.18813293,0.09954979,0.1522313,0.18970326,0.22772308,-0.63453686,-0.14460364,0.04017441,0.49186224,0.44101334,0.079869345,0.63949585,-0.3398982,-0.30536693,-0.13750036,-0.49192837,-0.19718334,0.035103757,0.0962411,-0.20534723,-0.0069325548,0.82749695,-0.020530775,0.201497,-0.4803392,0.8507342,-0.09402818,0.17616485,0.37851408,-0.20557596,0.44186842,0.010604736,0.4577775,-0.22937556,0.25000578,0.049409945,-0.25692293,0.046195038,0.26554435,0.693146,-0.05069068,-0.09798604,0.15974286,0.11449986,0.23388696,-0.30691475,-0.30355123,0.7845191,0.26414353,-0.25972593,-0.10747121,-0.7649346,-0.21152022,0.008733451,0.065457195,-0.5166193,0.4053944,0.18336475,-0.06350905,-0.244712,-0.018565297,0.033007562,0.2449141,0.12483453,0.12697028,-0.017637199,-0.8557299,0.5168888,0.3655117,0.45985276,-0.10521869,0.18202552,0.21041967,0.24619697,0.14156243,-0.30359447,-0.14919302,-0.37194425,0.37058076,0.5187885,-0.3123344,0.022668771,-0.01044111,0.22775233,-0.21366847,0.2643437,0.1627298,0.13901593,-0.06823984,0.05427516,-0.20146641,0.01689507,0.11652289,-0.12732811,0.18930379,-0.037318543,0.2743837,0.37760317,-0.41542196,-0.12989938,0.2574838,0.2648051,-0.023620674,-1.1399317,-0.4156523,0.025079064,0.25071105,-0.10427712,0.044318832,0.017918011,-0.22878496,0.020519411,0.21632299,0.23381321,0.049660206,-0.71438193,-0.0527001,0.60960704,-0.53205633,0.15377116,0.32985595,-0.32877266,-0.36400738,-0.29025462,-0.0717651,-0.04595842,-0.16258354,-0.540876,-0.24144617,-0.06729545,-0.020792373,2.1283045,-0.23990849,-0.18659948,-0.051481344,0.19586629,0.010612031,-0.4915703,0.32370976,-0.11360798,-0.18731631,0.072093114,-0.083949625,-0.020754946,0.17521742,-0.39663237,-0.12998481,-0.057192113,0.23820916,0.4499491,0.018262569,-0.030769082,0.12605964,-0.11827786,-0.5687776,-0.3952388,0.23882942,0.78625524,0.27870816,-0.027530435,-0.15502386,0.20786698,0.43548283,-0.0009038523,0.31338674,-0.06595472,2.0273223,-0.22201347,-0.6531059,0.47542715,0.26400375,-0.3147157,0.21785673,0.22026342,-0.08095157,0.0847575,0.24553965,-0.06521492,-0.15571015,-0.097661175,0.18328619,0.035034392,-0.037914205,-0.08942876,0.38343096,-0.35566524,-0.24537657,0.15469295,0.24383177,0.5867496,-0.0486377,-0.41822922,-0.0630222,-0.22113314,-0.1845765,-0.18854389,-0.30510208,0.008222166,-0.24907322,-0.34341568,0.3900252,0.0110212825,0.406874,0.24129388,-0.025004571,0.6215514,-0.36764365,0.024663284,-0.45099896,0.42148992,-0.12013734,-0.032297306,0.5887764,0.32308128,0.16417038,0.06150677,0.31120893,0.63756996,-0.14961845,-0.08417582,-0.37218893,0.49834827,0.09298247,0.017275233,0.43525246,-0.14670758,-0.12142711,-0.45903748,-0.6529836,-0.1548418,-0.15480818,-0.09194402,-0.03706259,-0.12415326,0.5186551,-0.5018849,-0.4189555,-0.032322444,0.04925227,-0.34861404,-0.02112386,-0.15232112,-0.15490322,0.6745539,0.23143604,0.021649444,0.22545543,1.0122172,-0.90464264,-0.29577294,0.054149605,0.048455354,0.6389533,0.04319878,-0.11480327,0.4936703,0.03495861,0.04151588,-0.20484878,-0.4183226,0.38927677,0.111093,0.15788741,-0.3000338,-0.24360374,0.6312496,0.54451114,0.4521028,0.23694548,-0.043000996,0.19504929,-0.16435485,-0.1310756,-1.9088625,-0.3649616,-0.19003132,-0.06123936,1.5573491,-0.22447388,-0.6345325,-0.0602772,-0.22427735,0.3413652,0.15170765,0.22347213,-0.119629286,0.3943304,0.6261622,0.30846784,0.158951,-0.4328679,-0.012628328,-0.10243707,0.33873302,-0.47756115,-0.48644412,0.77409905,0.26983866,0.069062665,-0.84900004,0.03140925,-0.708933,0.048351973,-0.12511368,-0.028273875,-0.23365898,0.06724753,-0.06719497,0.37817386,-0.013098218,-0.16298072,0.42629576,-0.26790404,-0.3764603,-0.10256859,-0.4326321,0.1641767,-0.14325576,-1.0153855,0.6954639,-0.34027427,-0.20593107,0.38886234,-0.028716102,0.12772834,-0.070300125,-0.398837,0.654207,-0.1359202,0.0017471323,-0.0073141046,-0.040802095,-0.1893576,-0.030553147,-0.55713266,0.4568075,-0.490446,-0.09280069,-0.24626164,-0.18541244,-0.30199936,-0.36953476,0.043462984,-0.24344434,0.10597109,0.11267492,0.5432016,0.0754606,0.04789724,-0.023035001,0.27039105,-0.98146844,0.38128886,-0.02900105,0.30545384,0.17601722,0.4164194]');
INSERT INTO public.products (product_id, product_name, category_id, is_deleted, created_by, created_at, product_image, status, image_embedding) VALUES (6, 'Jordan 1 Retro Low OG SP Travis Scott Black Phantom', 3, false, 138222, '2026-05-09 23:10:46.978859', '1779841157_master_6a163885327fb.jpg', true, '[-0.09347603,-0.18479557,0.33320782,0.1610509,-0.0043567484,-0.045334857,-0.28305888,0.19223227,0.13272145,0.05444493,0.10575629,0.045020096,-0.634873,-0.6306591,-0.14273898,0.0874227,0.64237845,-0.29179287,0.15955994,0.04225802,-0.27236146,-0.18029337,0.2801594,-0.026069488,0.41330385,0.19747783,-0.034461975,0.23714288,0.22969311,-0.29995096,0.08990726,-0.5951114,-0.1128975,0.2781337,-0.13510293,0.087836415,0.41187927,0.7196028,-0.3621984,1.3822265,-0.047101196,-0.1243071,0.2819265,-0.25012985,0.62563205,-0.8481757,0.10489959,-0.47428608,0.008519918,-0.3222629,0.119141765,0.3610567,0.23493534,0.012941269,-0.28677052,0.06238566,0.1453605,0.539297,-0.09699768,0.11620681,0.072958015,0.33555004,0.28237644,0.15150596,-0.08350052,-0.22513404,0.33870465,-0.22271127,-0.31103086,-0.2513573,-0.26661122,-0.43181697,-0.16431701,0.01298726,-0.15950838,-0.09636242,-0.029059682,-0.57464665,0.14763774,-0.037369747,-0.22604075,-0.13394475,-0.40331733,0.12595078,0.24984443,0.35199046,0.6221757,-0.05288544,0.4536756,-0.016450858,-0.14109811,0.4265209,-5.053361,0.585782,0.08509767,0.14937496,-0.23847656,-0.25513023,-0.5413573,0.6174574,-0.23766595,-0.5307714,0.2787752,-0.19213726,-0.24052916,0.64515024,-0.98585576,-0.14529261,-0.29654005,0.4443783,0.05512803,0.37377033,0.075365946,0.041981958,-0.2467803,-0.41445374,-0.13454893,-0.15539713,0.035002902,-0.28842747,0.097544216,0.05308399,-0.2783027,0.021136189,0.26428095,-0.3744144,-0.5287936,-0.10710201,0.17786497,-0.015996726,0.12385588,0.38801634,-0.27309856,0.72422576,-0.04948496,-0.09414301,0.021190133,0.3217178,-0.05836424,-0.17974333,0.0061546136,-0.21931046,-0.22672386,0.36790597,-0.11511196,0.25846574,0.059768006,0.039032087,0.50230724,0.36320758,-0.23102885,0.4126688,1.2177806,0.20058487,-0.05744794,0.109016836,0.29771748,0.22244874,0.2524627,-0.13895085,-0.44590876,-0.3643327,0.32498667,0.27012506,0.24369141,-0.13793628,0.061291907,-0.15509917,-0.01376473,-0.329539,-0.27683738,-0.5903788,0.1681176,0.07355215,-0.096589655,-0.71929985,0.33689666,0.06520963,-0.3025522,-0.38941342,0.88909084,-0.041248836,0.45799315,-0.10591552,0.015111279,0.22247203,0.08534843,0.42318824,0.053062495,-0.13803819,0.03325273,-0.090074554,0.20231776,0.60623497,0.8406706,0.23100826,-0.16774057,0.18456769,-0.24822211,0.48124754,-0.41339096,0.07518507,0.57235223,0.1027137,-0.5692932,-0.4508637,-0.59730244,-0.23162721,-0.15813744,0.3951825,-0.27829,0.067189515,0.22120523,-0.10099568,-0.71195906,0.25304803,-0.14925443,0.16703558,-0.023059689,-0.121508785,-0.3291292,-0.8362024,0.6974051,0.035484493,0.15845597,-0.24904662,0.15096216,-0.3549238,0.02907731,0.33628997,-0.57771266,-0.31991053,-0.32535386,0.5738366,0.17402288,0.2624217,-0.1140952,-0.011792232,0.05302146,-0.11948793,-0.05525419,-0.044338122,0.41890368,-0.016415525,0.09183957,-0.43246973,-0.097549416,0.06896706,-0.4021451,-0.18481515,0.059403963,-0.16174649,0.24003455,-0.41576025,0.089278534,0.3513057,0.40471372,-0.034360357,-1.0473014,-0.038979605,0.14182106,0.51868486,-0.20542708,-0.090893246,-0.18633965,0.39608163,0.10425687,0.10898021,-0.111672506,-0.3083793,-0.15059617,0.18657,0.50365984,-0.68379235,0.16477601,0.37602627,-0.40598327,-0.37661538,-0.20421611,-0.3918758,0.33564836,0.19605969,-0.6138989,-0.38336706,-0.035394184,-0.014751874,2.2126029,-0.16022418,0.013975648,-0.26098317,0.5006708,0.018561672,-0.2681761,0.5157458,-0.17678879,-0.059689473,0.042041212,-0.2611743,0.36170724,0.07534477,-0.5966182,0.1535416,0.2725231,0.017363071,0.5993654,-0.13610582,-0.2894641,0.24945371,0.27897394,-0.31386113,-0.2464946,0.127834,0.722136,0.20763129,0.024894673,0.095092565,0.13866806,0.07533218,-0.025481177,0.026338257,-0.07623025,1.5254557,-0.24493389,-0.5963005,0.4932461,0.3043117,0.0829073,0.2352024,-0.124308065,-0.20308049,-0.17120022,0.21621689,0.007719133,0.2824134,0.04290136,0.045359723,-0.43957496,-0.19519243,-0.28830013,0.18803042,-0.3650716,-0.3183802,0.09143135,0.30668232,0.71111435,-0.27010256,-0.28619182,-0.082795724,-0.32377723,-0.36955693,0.12763153,0.052555904,-0.4302405,-0.045250833,-0.016346224,0.31435096,-0.32549953,-0.007974808,0.1522212,-0.20426168,0.84853196,-0.375134,-0.27590227,-0.43870077,0.5903689,-0.0851721,-0.38036796,0.2791548,0.39084834,0.42161384,0.38591376,0.22927721,0.39221913,-0.027248563,-0.041841105,-0.1535071,0.34865877,0.023602102,0.13699198,0.08267628,-0.06027454,0.07621513,-0.29789045,-0.29541144,-0.2973832,0.23880999,0.24713118,0.2552826,-0.13495868,0.69294864,-0.65021014,-0.3353244,-0.19008905,-0.25959057,-0.7152915,0.36486405,0.05107725,-0.10101204,0.3417351,0.47912338,0.23146461,-0.0348554,0.8228533,-0.7059528,-0.20123704,0.34401006,-0.008355329,0.5542266,0.4414202,-0.24403012,0.2588667,0.063767515,0.28402627,-0.13759409,-0.38326558,0.4368412,0.3521497,0.32906362,-0.19115318,-0.103565075,0.48515424,0.83117586,0.28055143,0.75927377,-0.06524849,-0.18028,-0.12330268,0.09160146,-1.8749563,-0.2709461,-0.007868707,-0.030410357,1.0360947,-0.22170407,-0.19145434,-0.20438442,-0.29090774,0.3542444,0.39473188,0.22563289,0.09672864,0.3307383,0.24221176,0.04246922,0.3977516,-0.34355456,-0.09874076,0.15771973,-0.018315142,-0.09840031,-0.61473876,0.5449635,0.11170055,-0.1226312,-0.6693467,-0.096129276,-0.681993,0.00040689483,0.12417341,0.06810231,0.20755951,0.45224047,0.024429658,0.27141726,0.10399666,-0.16162142,0.26468676,0.19574611,-0.4060826,-0.16175139,-0.6919047,0.45201537,0.48939058,-0.46236795,0.45512757,-0.31355357,0.049528006,0.28899482,-0.21285705,0.19838658,0.26525342,-0.19255425,0.4260778,-0.09709193,-0.14367503,0.27994058,0.14746884,-0.21960153,-0.52633095,-0.7405252,0.27010775,-0.36326218,0.09350654,-0.16277838,-0.626741,-0.4150684,0.102686405,-0.3235398,0.1300831,-0.19165175,-0.0006727874,0.29299673,-0.2926738,0.08212944,0.030518934,-0.27129015,-0.7745424,-0.022443466,0.2049848,0.4852425,0.4052574,0.21239947]');
INSERT INTO public.products (product_id, product_name, category_id, is_deleted, created_by, created_at, product_image, status, image_embedding) VALUES (11, 'Vans Classic Slip-On Checkerboard', 4, false, 138222, '2026-05-28 01:38:13.004618', '1779907093_master_6a173a1501248.jpg', true, '[-0.25263524,-0.43236116,0.2182292,0.26722458,-0.020986306,0.52460486,0.07320122,-0.049305834,0.045011707,0.04941158,-0.2515365,0.010591684,-0.37542328,-0.373316,-0.19403158,-0.0046106745,0.29475492,-0.51889426,0.21403684,0.12320891,-0.24200907,-0.03626412,0.69895387,-0.3990788,0.34540415,0.2312443,-0.008654939,0.3596777,0.12546092,-0.188724,-0.06292115,-0.5202506,0.24225114,0.12135485,-0.3526296,-0.060236495,0.18084654,0.4365536,-0.15996602,1.7217594,-0.15068075,-0.3602241,0.4975944,-0.07316827,-0.17606017,-0.5543779,0.25467417,-0.41382945,0.30150947,0.113191746,-0.34760275,0.189284,0.2648988,0.073854774,-0.53505796,-0.2472302,0.021528814,0.1999667,-0.40470713,0.20206152,0.87107205,-0.03266389,0.34990838,-0.07807671,-0.102831915,0.061414283,0.18681453,0.35033602,0.14161664,0.40903032,-0.05210237,-0.36112767,-0.028733037,-0.15424177,0.05003819,-0.17878607,0.5799204,-0.656185,-0.17611964,-0.36130607,-0.25694844,-0.39848185,-0.19582725,-0.10611381,0.2880976,0.98859215,0.940262,-0.21269776,-0.12820888,-0.27390853,0.14278331,0.32539216,-5.2197165,0.68805677,0.05595372,0.31222275,-0.30651578,-0.27972838,-0.61699647,0.14257503,-0.055136785,-0.6870244,0.050864477,-0.17824608,-0.45800427,0.52896225,0.10857029,-0.08138294,-0.1966024,0.003845483,0.08998889,0.26954234,0.24204116,-0.2759541,0.0005693212,-0.16806886,-0.13419032,-0.25787836,-0.13712941,-0.39627877,0.21476495,0.20772809,-0.3338428,0.12826198,0.21753637,-0.1473791,-0.2784452,0.015664637,-0.16115542,-0.24148013,-0.4135547,0.08669934,-0.13653474,0.7336949,0.2669099,0.33835602,0.09428267,0.44053787,-0.20175411,-0.0019396544,0.0021376126,-0.2827346,0.07397623,0.21795645,-0.23939681,0.2834791,-0.22888686,-0.45133358,0.04576271,0.32905874,0.3885864,0.39632764,1.2934328,-0.18094464,-0.22922188,0.14210935,0.12300942,-0.254573,-0.00032410212,0.24911831,-0.5064995,-0.21581824,0.36622837,0.37173718,0.29164973,0.3383027,0.6832398,-0.32340574,-0.013099497,-0.23300374,-0.024835669,-0.27624914,0.17413482,0.08206017,-0.00092091225,-0.23749003,0.82076323,0.24286337,0.38656068,-0.52080864,0.5721383,-0.20625728,0.060119648,0.16956611,-0.4516929,0.23025727,-0.02771671,0.5382424,-0.0233673,0.23867723,0.49208656,-0.27378908,-0.24691647,0.30972123,0.6846771,-0.013180755,-0.24790022,-0.46962303,0.06996682,0.35325667,-0.6089078,-0.5071054,0.5867936,-0.1430321,-0.09166063,-0.05235444,-0.782573,-0.45887887,0.040158257,0.070208974,-0.13911232,0.27302054,0.5277589,-0.2394997,-0.1037094,0.04689949,0.19538006,0.5021838,0.13602656,0.06953768,-0.097485304,-0.44783708,0.38341287,0.056590788,0.9331506,-0.39479086,0.25681022,0.16725607,0.0883835,0.22701058,-0.1436707,-0.28931534,0.10373908,0.24696255,0.4128509,-0.30453554,-0.282664,-0.50735414,0.29405293,-0.3998109,0.03910126,0.19095153,0.30288595,0.1292772,0.27007425,-0.1585606,-0.09002207,0.022718754,0.0065514,0.29433584,-0.012768526,0.32390013,0.38808635,-0.38683942,-0.16657269,0.015420316,0.71412444,-0.07002412,-1.1045705,-0.27942914,-0.20039655,0.14739044,-0.28684753,-0.61370087,0.28211957,-0.12876096,-0.26113614,0.36883968,0.5173019,-0.15607835,-0.5162222,-0.24013205,0.4429179,-0.43106362,0.4202702,0.22146946,-0.1496895,-0.4075757,-0.50315595,-0.15930232,-0.2168,0.08251896,-0.52936447,-0.3020529,-0.14769457,-0.17162913,2.3641553,0.23608741,-0.12188308,-0.18021461,0.1774125,-0.017919248,-0.120345615,0.24307945,0.0008174251,-0.12033906,-0.1644474,-0.3806916,-0.1112671,0.2369511,-0.43042484,-0.064457744,-0.02032416,0.28250945,0.10463217,0.22917078,0.3053861,0.064795285,0.09438723,-0.25677678,-0.5700232,0.40145773,0.7319454,0.19988018,0.44527465,-0.31975466,0.3490623,0.41980594,-0.104239374,0.54337794,-0.05873482,2.4352443,-0.46525076,-0.8519111,0.6988163,0.22235121,-0.08768239,0.18614821,0.31463665,-0.32258525,-0.37163794,0.31293133,0.1177641,-0.49376497,-0.0636622,-0.022151344,-0.12981668,0.15324812,-0.1754898,0.28113404,-0.64671516,-0.29394576,0.29903555,-0.04547398,0.45530823,0.27472827,-0.46161297,-0.29474753,-0.3403518,-0.3774993,-0.35855263,-0.33214414,-0.109104656,-0.42653817,-0.40853032,0.80755,0.3409973,1.1850699,0.29421726,0.07521311,0.5330286,-0.31326014,0.14533505,-0.5697268,0.16268136,-0.08286089,0.10356279,0.6348877,0.26397327,0.18285112,0.043161545,0.3560865,0.5978702,0.30867788,0.26655924,-0.34428558,0.6282561,0.6295849,0.27585688,0.35339838,-0.025297921,0.072709195,-0.5999223,-0.5103572,0.05791251,0.08358498,0.16511534,0.098214954,-0.5301939,0.49332985,-0.5221486,-0.54449415,-0.03593989,-0.19563001,-0.5077673,0.25095242,-0.12114954,-0.034617633,0.6881015,-0.06627217,-0.2396231,0.016298741,0.36163723,-0.56471443,-0.07283293,0.058700554,-0.3669867,0.6825557,0.4112083,-0.0840594,0.31905517,0.2782249,0.561671,0.074399345,-0.6491674,0.29289427,0.1473141,0.46897128,0.031389236,-0.20225447,0.45480153,0.7023032,-0.02529369,0.17935725,0.15120123,0.057926696,-0.18554847,-0.34101763,-1.8408616,-0.21310455,-0.3863825,-0.34724116,1.2775975,-0.17506179,-0.4187171,0.0663473,-0.072282635,0.5740335,0.41481405,0.3135593,-0.13182664,0.39738777,0.4691896,0.31087846,-0.019673277,-0.43284014,-0.113006935,-0.3538919,0.028744161,-0.2437763,-0.17273648,0.50440854,0.10634351,-0.11387051,-0.8452438,0.30168143,-0.43550265,0.29091442,-0.06173988,-0.10014528,-0.19456655,0.092314065,-0.19631691,0.26737696,0.06546142,0.114639975,0.28390816,-0.01348087,-0.51250774,-0.28207436,-0.24006091,0.29622394,-0.068331815,-0.6447426,0.39197505,-0.09699342,-0.11928801,0.35021487,-0.19015805,0.06299934,0.029335987,-0.44963044,0.6089325,-0.25073284,-0.24098364,0.3225233,0.098022185,-0.23860028,-0.205868,-0.05524507,0.3742392,-0.40897614,-0.08130474,-0.46484047,-0.17420807,-0.43557978,0.012591226,0.20489115,0.023725472,-0.23734863,0.0020789932,0.14636701,-0.1226661,0.024133388,-0.5321131,0.05229067,-0.7699318,0.45130304,-0.15145981,0.16549417,-0.24118373,0.7375135]');
INSERT INTO public.products (product_id, product_name, category_id, is_deleted, created_by, created_at, product_image, status, image_embedding) VALUES (10, 'test shoe', 17, true, 138222, '2026-05-27 07:28:40.593283', '1779841720_master_6a163ab890dae.jpg', false, '[-0.28687155,-0.009447496,0.24155691,0.20169702,-0.35329565,-0.010317312,-0.12017762,-0.055725444,0.19819468,-0.40619698,0.13760765,-0.14388211,-0.67398244,-0.09475455,-0.44127932,0.24134818,1.3526431,0.23980545,0.16561377,0.0008125007,-0.5904113,-0.16738696,0.16733946,-0.3290499,-0.20856722,0.47121018,0.21269485,0.0811219,-0.23460868,0.097190015,0.023761392,-0.095907055,0.06723145,0.26279277,-0.57392526,0.16463691,0.46803504,0.819545,-0.014781628,1.164246,-0.30374315,-0.59751004,0.11246516,-0.5879108,0.36508694,-0.28567877,0.06740277,-0.11298322,-0.04736208,-0.2644331,0.31149226,0.29083663,0.11263322,-0.18946585,-0.47344953,0.3526506,0.13888255,0.3359523,-0.4627412,0.06055145,0.81667244,-0.012488436,0.6090824,-0.5491482,0.4200054,0.04399846,0.33345553,0.608736,-0.21371505,-0.13719973,-0.2695019,-0.13884431,0.27413836,-0.22494304,0.032986216,-0.28423727,-0.33139515,-0.09869778,0.17597698,-0.16887054,-0.21401289,-0.25753736,0.15112543,0.023972075,0.37046728,0.21629706,0.40219778,0.06816418,0.14329015,0.09809614,0.3992585,0.5011368,-4.5969725,0.75547093,-0.06355125,0.18326792,-0.041363247,-0.24465021,-0.8623926,0.17301399,-0.09849941,-0.53036416,0.6462284,0.38638875,0.08001809,0.40502757,-0.6277967,-0.44861352,-0.3408397,-0.08925553,0.13047035,0.41407132,0.45806012,-0.0099267755,-0.008533685,-0.2228038,-0.5087038,-0.35945162,0.12346778,-0.31421813,-0.046587918,0.3652729,0.343849,0.15559974,0.22924842,0.09493283,-0.3295756,-0.38267168,0.3231455,0.15582775,-0.2895788,0.026201006,-0.21975787,0.7673766,-0.14900723,0.30338874,0.04127737,0.41617587,-0.024255987,0.0975073,-0.17170201,0.034878686,-0.20119959,0.6262282,0.29345882,0.3533627,-0.37463093,-0.49776304,-0.056332882,0.2454493,0.3186758,0.1511974,0.94643575,0.08207633,-0.41031232,0.4341425,0.40196165,0.5684219,-0.17576161,0.0806694,-0.43564922,-0.1440254,0.15838647,0.047324818,0.6022106,-0.010507457,0.15598106,-0.38182357,-0.09522074,0.54245657,-0.31707615,-0.5793624,0.19613895,-0.04021707,-0.06586403,-0.64439917,0.42087454,0.14645846,0.65180016,-0.33038333,0.3100433,0.0072843954,0.25805962,0.3925261,-0.18759727,0.71574265,0.22553483,0.6025152,-0.59573996,0.42026138,-0.16755693,-0.02248032,0.0692906,0.11753483,0.66663563,0.5382502,0.25330344,-0.05206476,-0.6454575,0.19297871,-0.09037291,-0.21462667,0.62719274,-0.21673453,-0.45110026,0.07571334,-0.5426903,-0.47413558,-0.179686,0.13525026,0.042764213,0.31032553,0.1774424,-0.1215862,-0.24403748,0.4111866,0.48866293,0.21351507,0.27289438,0.32167256,0.09542343,-0.595492,0.54177094,-0.18351838,0.37929836,0.163471,0.33334187,0.07938986,0.20823926,0.42874694,0.11846483,-0.5688696,0.08533045,0.3649624,0.50403607,0.10192044,-0.25093743,0.1052467,-0.12651944,-0.054146297,0.06920721,-0.008694626,0.290604,-0.122868486,0.32107243,-0.20358294,-0.053622633,0.4765737,-0.35426304,0.1458384,-0.0993504,-0.07083378,0.29238084,-0.21717934,0.60992247,0.3481273,-0.14956586,0.17279924,-0.6319041,0.17641029,-0.51363575,0.28299004,-0.16861513,-0.3874343,-0.72754216,0.82732296,-0.17627628,0.09149102,-0.02343698,-0.3080591,-0.49526447,-0.06989376,-0.21805848,0.055295054,0.15491296,0.53886265,0.3143052,-0.5228653,-0.35953614,-0.38140583,0.21408239,0.49864456,-0.030516397,-0.48313934,-0.022087904,-0.05568235,2.0207489,-0.2160857,-0.43779862,-0.31972802,0.23226906,-0.17046955,-0.18044934,0.7649414,-0.089306936,0.07394132,-0.26322138,-0.6307834,0.2539898,0.08996068,-0.34500092,0.33602887,-0.13221185,0.28053254,-0.25021264,-0.30681622,-0.33562735,0.18290497,0.06822218,-0.24495289,0.05461444,0.33336857,0.76538455,0.20373443,0.016385894,-0.16068989,0.17400645,0.14975803,-0.42380872,-0.003601376,0.17884707,1.2290605,-0.2186237,-0.46154222,0.7431659,-0.05445029,0.5774843,0.3956832,0.23814428,-0.2812907,0.15870173,0.0129259555,-0.09316196,0.065467514,-0.06896221,0.026413985,-0.12827843,0.014654305,0.41048142,-0.14720379,-0.37667838,0.027451046,-0.12152964,0.26416403,0.28160268,0.23450102,-0.3341591,-0.30966365,-0.13191213,-0.07734138,0.002274599,-0.1743013,-0.035735544,-0.66759217,-0.15315521,0.4047766,0.27331066,0.45410213,-0.07747422,-0.4229227,0.7933408,-0.34510672,0.2842102,0.18592218,-0.33156383,0.033438884,-0.07490185,0.84101474,-0.08148291,-0.16623247,0.15557177,0.22915289,0.22836028,0.06738523,-0.17436805,-0.2939907,0.38458773,-0.0057505034,0.51883817,0.19778205,0.17607214,0.3488956,-0.033568144,0.021691527,-0.09033627,-0.21192548,0.5457831,0.4096122,-0.17390896,0.020136667,-0.944774,-0.4868942,-0.3688516,-0.17969409,-0.5807882,0.49534693,0.7260813,-0.475246,0.55481255,0.0076987334,-0.08645997,0.51659465,1.1507825,-0.28258285,0.061819617,-0.19862434,-0.1745369,0.23604488,0.089901835,-0.26777384,0.4513617,0.35737467,0.39011404,0.09260182,-0.48133546,0.522975,-0.2992268,0.17347705,-0.32055497,-0.48377556,0.5026247,0.31763107,0.3303255,0.95727265,-0.039339595,-0.09222102,0.078060515,0.3191415,-1.5649279,-0.20364322,-0.3458002,-0.758231,1.1920285,-0.20660472,-0.30822554,0.10613924,-0.22837426,0.51440763,0.51128757,-0.015207093,0.12476305,0.16690975,0.3347225,0.24871522,-0.40635332,-0.23971923,0.22209892,-0.44992763,0.21512324,0.014755411,0.03404636,0.3574205,0.18449761,0.31495175,-0.25412256,-0.089687645,-0.49687606,0.117575295,0.3049805,-0.058082182,0.0249898,0.52463275,-0.22057097,-0.12716003,0.43246794,0.23366824,0.061985157,-0.097914614,0.022793965,-0.13284568,-0.05361539,0.21470663,0.20947483,-0.45356306,0.33313045,0.02099111,-0.524079,0.68574697,-0.6208377,-0.33035585,0.122387536,-0.2603395,0.2796695,-0.03826021,-0.13568372,0.48312622,0.3319204,0.21138054,-0.04836049,-0.4100653,0.2333822,-0.13193624,0.12242808,-0.3723574,-0.38414568,-0.3888912,-0.22578871,-0.42669374,0.3577011,0.19324231,-0.23342574,0.31559953,-0.12295563,0.21255888,-0.31488445,-0.18625,-0.13673405,0.1122016,0.22065118,0.15123624,0.19029641,0.3484959]');


--
-- TOC entry 5305 (class 0 OID 51209)
-- Dependencies: 251
-- Data for Name: shelves; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.shelves (shelf_id, shelf_name, total_tiers, slots_per_tier, max_capacity_per_slot, layout, created_at, updated_at, is_deleted, status) VALUES (6, 'F', 4, 5, 4, '{"1": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "2": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "3": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "4": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}}', '2026-05-21 05:13:47.132692', '2026-05-21 05:13:47.132692', true, true);
INSERT INTO public.shelves (shelf_id, shelf_name, total_tiers, slots_per_tier, max_capacity_per_slot, layout, created_at, updated_at, is_deleted, status) VALUES (7, 'I', 3, 3, 3, '{"1": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "2": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "3": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "4": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}}', '2026-05-21 05:18:12.282757', '2026-05-21 05:18:12.282757', true, true);
INSERT INTO public.shelves (shelf_id, shelf_name, total_tiers, slots_per_tier, max_capacity_per_slot, layout, created_at, updated_at, is_deleted, status) VALUES (8, 'G', 4, 5, 4, '{"1": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "2": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "3": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "4": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}}', '2026-05-21 05:18:44.604026', '2026-05-21 05:18:44.604026', true, true);
INSERT INTO public.shelves (shelf_id, shelf_name, total_tiers, slots_per_tier, max_capacity_per_slot, layout, created_at, updated_at, is_deleted, status) VALUES (3, 'C', 4, 5, 4, '{"1": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "2": {"01": [23, 2, 2, 2], "02": [2, 2, 2, 2], "03": [2, 2, 28, 28], "04": [3, 1, 1, 1], "05": [1, 3, 3, 3], "06": [5, 5, 5, 5]}, "3": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "4": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}}', '2026-05-21 05:13:47.132692', '2026-05-21 05:13:47.132692', true, true);
INSERT INTO public.shelves (shelf_id, shelf_name, total_tiers, slots_per_tier, max_capacity_per_slot, layout, created_at, updated_at, is_deleted, status) VALUES (5, 'E', 4, 5, 4, '{"1": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "2": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "3": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "4": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}}', '2026-05-21 05:13:47.132692', '2026-05-21 05:13:47.132692', true, true);
INSERT INTO public.shelves (shelf_id, shelf_name, total_tiers, slots_per_tier, max_capacity_per_slot, layout, created_at, updated_at, is_deleted, status) VALUES (4, 'D', 4, 5, 4, '{"1": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "2": {"01": [5, 5, 5, 5], "02": [4, 4, 4, 4], "03": [6, 6, 6], "04": [], "05": [], "06": []}, "3": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "4": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}}', '2026-05-21 05:13:47.132692', '2026-05-21 05:13:47.132692', true, true);
INSERT INTO public.shelves (shelf_id, shelf_name, total_tiers, slots_per_tier, max_capacity_per_slot, layout, created_at, updated_at, is_deleted, status) VALUES (2, 'B', 4, 5, 4, '{"1": {"01": ["14"], "02": [], "03": [], "04": [], "05": [], "06": []}, "2": {"01": [19, 19, 19, 19], "02": [19, 19, 19, 19], "03": [24, 21, 22, 22], "04": [22, 22, 21, 21], "05": [21, 21, 21, 22], "06": [22, 23, 23, 23]}, "3": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "4": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}}', '2026-05-21 05:13:47.132692', '2026-05-21 05:13:47.132692', true, true);
INSERT INTO public.shelves (shelf_id, shelf_name, total_tiers, slots_per_tier, max_capacity_per_slot, layout, created_at, updated_at, is_deleted, status) VALUES (9, 'K2', 2, 2, 4, '{"1": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "2": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "3": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}, "4": {"01": [], "02": [], "03": [], "04": [], "05": [], "06": []}}', '2026-05-28 00:46:27.383842', '2026-05-28 00:46:27.383842', true, true);
INSERT INTO public.shelves (shelf_id, shelf_name, total_tiers, slots_per_tier, max_capacity_per_slot, layout, created_at, updated_at, is_deleted, status) VALUES (1, 'A', 4, 5, 4, '{"1": {"01": [15, 18, 18, 11], "02": [11, 11, 11, 10], "03": [10, 8, 8, 8], "04": [8, 8, 8, 8], "05": [9, "12", "12", "12"], "06": ["12", "12", "12", "12"]}, "2": {"01": [17, 17, 17, 17], "02": [17, 17, 17, 17], "03": [17, 17, 17, "14"], "04": ["14", "14", "14", "14"], "05": [16, 16, 16, 16], "06": [16, 16, 15, 15]}, "3": {"01": ["12", "12", "12", "3"], "02": ["3", "3", "3", "3"], "03": ["20", "20", "3", "7"], "04": ["20", "20", "20", "20"], "05": ["20", "20", "20", "20"], "06": ["7", "7", "7", "7"]}, "4": {"01": ["3", "3", "3", "3"], "02": ["14", "14", "14", "14"], "03": ["14", "14", "14", "14"], "04": ["14", "14"], "05": ["7", "7", "7", "7"], "06": ["14", "14", "14", "14"]}}', '2026-05-21 05:13:47.132692', '2026-05-21 05:13:47.132692', true, true);


--
-- TOC entry 5308 (class 0 OID 51224)
-- Dependencies: 254
-- Data for Name: system_audit_logs; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (1, 333011, 'UPDATE', 'tickets', 20, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 20, "batch_code": "LH01", "created_at": "2026-05-30T02:04:27.782667", "is_deleted": false, "manager_id": 138222, "ticket_code": "PN-260530-0001", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 20, "batch_code": "LH01", "created_at": "2026-05-30T02:04:27.782667", "is_deleted": false, "manager_id": 138222, "ticket_code": "PN-260530-0001", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:16:27.642287');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (2, 333011, 'UPDATE', 'ticket_details', 37, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 5, "detail_id": 37, "ticket_id": 20, "created_at": "2026-05-30T02:04:27.782667", "variant_id": 21, "processed_qty": 0}', '{"note": "", "is_diff": false, "qr_code": "https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=20&vid=21", "quantity": 5, "detail_id": 37, "ticket_id": 20, "created_at": "2026-05-30T02:04:27.782667", "variant_id": 21, "processed_qty": 5}', '2026-06-02 04:16:49.437556');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (3, 333011, 'UPDATE', 'product_variants', 21, '{"sku": "NI-NBPL-RED-41", "size": "41", "color": "RED", "stock": 1, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 2, "variant_id": 21, "reserved_stock": 0}', '{"sku": "NI-NBPL-RED-41", "size": "41", "color": "RED", "stock": 6, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 2, "variant_id": 21, "reserved_stock": 0}', '2026-06-02 04:17:05.719301');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (4, 333011, 'UPDATE', 'tickets', 20, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 20, "batch_code": "LH01", "created_at": "2026-05-30T02:04:27.782667", "is_deleted": false, "manager_id": 138222, "ticket_code": "PN-260530-0001", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "COMPLETED", "is_diff": false, "staff_id": 333011, "ticket_id": 20, "batch_code": "LH01", "created_at": "2026-05-30T02:04:27.782667", "is_deleted": false, "manager_id": 138222, "ticket_code": "PN-260530-0001", "ticket_type": "IMPORT", "completed_at": "2026-06-02T04:17:05.719301"}', '2026-06-02 04:17:05.719301');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (5, 138222, 'INSERT', 'tickets', 21, NULL, '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '2026-06-02 04:17:24.889506');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (6, 138222, 'INSERT', 'ticket_details', 38, NULL, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 2, "detail_id": 38, "ticket_id": 21, "created_at": "2026-06-02T04:17:24.889506", "variant_id": 5, "processed_qty": 0}', '2026-06-02 04:17:24.889506');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (7, 138222, 'UPDATE', 'product_variants', 5, '{"sku": "CO-CTAS-BLA-39", "size": "39", "color": "BLACK", "stock": 10, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 9, "variant_id": 5, "reserved_stock": 0}', '{"sku": "CO-CTAS-BLA-39", "size": "39", "color": "BLACK", "stock": 10, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 9, "variant_id": 5, "reserved_stock": 2}', '2026-06-02 04:17:24.889506');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (8, 333011, 'UPDATE', 'tickets', 21, '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '2026-06-02 04:17:29.665367');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (9, 333011, 'UPDATE', 'tickets', 21, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '{"status": "PAUSED", "is_diff": false, "staff_id": 333011, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '2026-06-02 04:17:31.934275');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (10, 138222, 'UPDATE', 'tickets', 21, '{"status": "PAUSED", "is_diff": false, "staff_id": 333011, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '{"status": "PAUSED", "is_diff": false, "staff_id": 711594, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '2026-06-02 04:17:40.156329');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (11, 711594, 'UPDATE', 'tickets', 21, '{"status": "PAUSED", "is_diff": false, "staff_id": 711594, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 711594, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '2026-06-02 04:18:18.104787');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (12, 711594, 'UPDATE', 'ticket_details', 38, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 2, "detail_id": 38, "ticket_id": 21, "created_at": "2026-06-02T04:17:24.889506", "variant_id": 5, "processed_qty": 0}', '{"note": "[{\"shelf_id\":\"undefined\",\"slot_code\":\"2-04\",\"qty\":2}]", "is_diff": false, "qr_code": null, "quantity": 2, "detail_id": 38, "ticket_id": 21, "created_at": "2026-06-02T04:17:24.889506", "variant_id": 5, "processed_qty": 2}', '2026-06-02 04:18:35.905855');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (43, 138222, 'INSERT', 'ticket_details', 42, NULL, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 10, "detail_id": 42, "ticket_id": 23, "created_at": "2026-06-02T04:32:27.121314", "variant_id": 20, "processed_qty": 0}', '2026-06-02 04:32:27.121314');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (14, 711594, 'UPDATE', 'ticket_details', 38, '{"note": "[{\"shelf_id\":\"undefined\",\"slot_code\":\"2-04\",\"qty\":2}]", "is_diff": false, "qr_code": null, "quantity": 2, "detail_id": 38, "ticket_id": 21, "created_at": "2026-06-02T04:17:24.889506", "variant_id": 5, "processed_qty": 2}', '{"note": "[{\"shelf_id\":\"undefined\",\"slot_code\":\"2-04\",\"qty\":1},{\"shelf_id\":\"undefined\",\"slot_code\":\"2-05\",\"qty\":1}]", "is_diff": false, "qr_code": null, "quantity": 2, "detail_id": 38, "ticket_id": 21, "created_at": "2026-06-02T04:17:24.889506", "variant_id": 5, "processed_qty": 2}', '2026-06-02 04:18:46.772827');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (19, 711594, 'UPDATE', 'tickets', 21, '{"status": "PROCESSING", "is_diff": false, "staff_id": 711594, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '{"status": "PAUSED", "is_diff": false, "staff_id": 711594, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '2026-06-02 04:19:32.994018');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (20, 711594, 'UPDATE', 'tickets', 21, '{"status": "PAUSED", "is_diff": false, "staff_id": 711594, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 711594, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '2026-06-02 04:22:32.84757');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (21, 711594, 'UPDATE', 'ticket_details', 38, '{"note": "[{\"shelf_id\":\"undefined\",\"slot_code\":\"2-04\",\"qty\":1},{\"shelf_id\":\"undefined\",\"slot_code\":\"2-05\",\"qty\":1}]", "is_diff": false, "qr_code": null, "quantity": 2, "detail_id": 38, "ticket_id": 21, "created_at": "2026-06-02T04:17:24.889506", "variant_id": 5, "processed_qty": 2}', '{"note": "[{\"shelf_id\":\"undefined\",\"slot_code\":\"2-04\",\"qty\":1},{\"shelf_id\":\"undefined\",\"slot_code\":\"2-05\",\"qty\":1}]", "is_diff": false, "qr_code": null, "quantity": 2, "detail_id": 38, "ticket_id": 21, "created_at": "2026-06-02T04:17:24.889506", "variant_id": 5, "processed_qty": 2}', '2026-06-02 04:22:45.281294');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (22, 711594, 'UPDATE', 'product_variants', 5, '{"sku": "CO-CTAS-BLA-39", "size": "39", "color": "BLACK", "stock": 10, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 9, "variant_id": 5, "reserved_stock": 2}', '{"sku": "CO-CTAS-BLA-39", "size": "39", "color": "BLACK", "stock": 8, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 9, "variant_id": 5, "reserved_stock": 0}', '2026-06-02 04:22:47.220947');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (23, 711594, 'UPDATE', 'tickets', 21, '{"status": "PROCESSING", "is_diff": false, "staff_id": 711594, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": null}', '{"status": "COMPLETED", "is_diff": false, "staff_id": 711594, "ticket_id": 21, "batch_code": "LH-260602-001", "created_at": "2026-06-02T04:17:24.889506", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0001", "ticket_type": "EXPORT", "completed_at": "2026-06-02T04:22:47.220947"}', '2026-06-02 04:22:47.220947');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (24, 138222, 'INSERT', 'tickets', 22, NULL, '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 22, "batch_code": "LH-260602-002", "created_at": "2026-06-02T04:25:34.11142", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0002", "ticket_type": "EXPORT", "completed_at": null}', '2026-06-02 04:25:34.11142');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (25, 138222, 'INSERT', 'ticket_details', 39, NULL, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 2, "detail_id": 39, "ticket_id": 22, "created_at": "2026-06-02T04:25:34.11142", "variant_id": 9, "processed_qty": 0}', '2026-06-02 04:25:34.11142');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (26, 138222, 'UPDATE', 'product_variants', 9, '{"sku": "JO-J1RLOSTSBP-WHI-42", "size": "42", "color": "WHITE", "stock": 3, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 6, "variant_id": 9, "reserved_stock": 0}', '{"sku": "JO-J1RLOSTSBP-WHI-42", "size": "42", "color": "WHITE", "stock": 3, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 6, "variant_id": 9, "reserved_stock": 2}', '2026-06-02 04:25:34.11142');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (58, 333011, 'UPDATE', 'product_variants', 14, '{"sku": "AD-ASV-BLA-40", "size": "40", "color": "BLACK", "stock": 5, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 4, "variant_id": 14, "reserved_stock": 5}', '{"sku": "AD-ASV-BLA-40", "size": "40", "color": "BLACK", "stock": 0, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 4, "variant_id": 14, "reserved_stock": 0}', '2026-06-02 04:35:38.304946');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (27, 333011, 'UPDATE', 'tickets', 22, '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 22, "batch_code": "LH-260602-002", "created_at": "2026-06-02T04:25:34.11142", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0002", "ticket_type": "EXPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 22, "batch_code": "LH-260602-002", "created_at": "2026-06-02T04:25:34.11142", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0002", "ticket_type": "EXPORT", "completed_at": null}', '2026-06-02 04:25:48.694419');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (28, 333011, 'UPDATE', 'ticket_details', 39, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 2, "detail_id": 39, "ticket_id": 22, "created_at": "2026-06-02T04:25:34.11142", "variant_id": 9, "processed_qty": 0}', '{"note": "[{\"shelf_id\":\"1\",\"slot_code\":\"1-05\",\"qty\":2}]", "is_diff": false, "qr_code": null, "quantity": 2, "detail_id": 39, "ticket_id": 22, "created_at": "2026-06-02T04:25:34.11142", "variant_id": 9, "processed_qty": 2}', '2026-06-02 04:26:05.428861');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (29, 333011, 'UPDATE', 'product_variants', 9, '{"sku": "JO-J1RLOSTSBP-WHI-42", "size": "42", "color": "WHITE", "stock": 3, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 6, "variant_id": 9, "reserved_stock": 2}', '{"sku": "JO-J1RLOSTSBP-WHI-42", "size": "42", "color": "WHITE", "stock": 1, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 6, "variant_id": 9, "reserved_stock": 0}', '2026-06-02 04:26:07.573952');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (30, 333011, 'UPDATE', 'tickets', 22, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 22, "batch_code": "LH-260602-002", "created_at": "2026-06-02T04:25:34.11142", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0002", "ticket_type": "EXPORT", "completed_at": null}', '{"status": "COMPLETED", "is_diff": false, "staff_id": 333011, "ticket_id": 22, "batch_code": "LH-260602-002", "created_at": "2026-06-02T04:25:34.11142", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0002", "ticket_type": "EXPORT", "completed_at": "2026-06-02T04:26:07.573952"}', '2026-06-02 04:26:07.573952');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (31, 138222, 'UPDATE', 'categories', 17, '{"logo": "brand_test_1779472259.jpg", "status": true, "created_at": "2026-05-23T00:50:59.71931", "created_by": 138222, "is_deleted": false, "category_id": 17, "description": null, "category_name": "brand_test"}', '{"logo": "brand_test_1780349251.jpg", "status": true, "created_at": "2026-05-23T00:50:59.71931", "created_by": 138222, "is_deleted": false, "category_id": 17, "description": null, "category_name": "brand_test"}', '2026-06-02 04:27:31.556161');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (32, 138222, 'UPDATE', 'product_variants', 25, '{"sku": "BR-TS-BLA-39", "size": "39", "color": "BLACK", "stock": 5, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 10, "variant_id": 25, "reserved_stock": 0}', '{"sku": "BR-TS-BLA-39", "size": "39", "color": "BLACK", "stock": 5, "status": false, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": true, "product_id": 10, "variant_id": 25, "reserved_stock": 0}', '2026-06-02 04:29:59.405856');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (33, 138222, 'UPDATE', 'product_variants', 26, '{"sku": "BR-TS-RED-41", "size": "41", "color": "RED", "stock": 10, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 10, "variant_id": 26, "reserved_stock": 0}', '{"sku": "BR-TS-RED-41", "size": "41", "color": "RED", "stock": 10, "status": false, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": true, "product_id": 10, "variant_id": 26, "reserved_stock": 0}', '2026-06-02 04:30:06.280715');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (34, 138222, 'UPDATE', 'product_variants', 27, '{"sku": "BR-TS-BLA-41", "size": "41", "color": "BLACK", "stock": 6, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 10, "variant_id": 27, "reserved_stock": 0}', '{"sku": "BR-TS-BLA-41", "size": "41", "color": "BLACK", "stock": 6, "status": false, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": true, "product_id": 10, "variant_id": 27, "reserved_stock": 0}', '2026-06-02 04:30:10.65205');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (35, 138222, 'UPDATE', 'product_variants', 25, '{"sku": "BR-TS-BLA-39", "size": "39", "color": "BLACK", "stock": 5, "status": false, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": true, "product_id": 10, "variant_id": 25, "reserved_stock": 0}', '{"sku": "BR-TS-BLA-39", "size": "39", "color": "BLACK", "stock": 5, "status": false, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": true, "product_id": 10, "variant_id": 25, "reserved_stock": 0}', '2026-06-02 04:30:14.068432');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (36, 138222, 'UPDATE', 'product_variants', 26, '{"sku": "BR-TS-RED-41", "size": "41", "color": "RED", "stock": 10, "status": false, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": true, "product_id": 10, "variant_id": 26, "reserved_stock": 0}', '{"sku": "BR-TS-RED-41", "size": "41", "color": "RED", "stock": 10, "status": false, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": true, "product_id": 10, "variant_id": 26, "reserved_stock": 0}', '2026-06-02 04:30:14.068432');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (37, 138222, 'UPDATE', 'product_variants', 27, '{"sku": "BR-TS-BLA-41", "size": "41", "color": "BLACK", "stock": 6, "status": false, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": true, "product_id": 10, "variant_id": 27, "reserved_stock": 0}', '{"sku": "BR-TS-BLA-41", "size": "41", "color": "BLACK", "stock": 6, "status": false, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": true, "product_id": 10, "variant_id": 27, "reserved_stock": 0}', '2026-06-02 04:30:14.068432');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (38, 138222, 'UPDATE', 'products', 10, '{"status": true, "created_at": "2026-05-27T07:28:40.593283", "created_by": 138222, "is_deleted": false, "product_id": 10, "category_id": 17, "product_name": "test shoe", "product_image": "1779841720_master_6a163ab890dae.jpg", "image_embedding": "[-0.28687155,-0.009447496,0.24155691,0.20169702,-0.35329565,-0.010317312,-0.12017762,-0.055725444,0.19819468,-0.40619698,0.13760765,-0.14388211,-0.67398244,-0.09475455,-0.44127932,0.24134818,1.3526431,0.23980545,0.16561377,0.0008125007,-0.5904113,-0.16738696,0.16733946,-0.3290499,-0.20856722,0.47121018,0.21269485,0.0811219,-0.23460868,0.097190015,0.023761392,-0.095907055,0.06723145,0.26279277,-0.57392526,0.16463691,0.46803504,0.819545,-0.014781628,1.164246,-0.30374315,-0.59751004,0.11246516,-0.5879108,0.36508694,-0.28567877,0.06740277,-0.11298322,-0.04736208,-0.2644331,0.31149226,0.29083663,0.11263322,-0.18946585,-0.47344953,0.3526506,0.13888255,0.3359523,-0.4627412,0.06055145,0.81667244,-0.012488436,0.6090824,-0.5491482,0.4200054,0.04399846,0.33345553,0.608736,-0.21371505,-0.13719973,-0.2695019,-0.13884431,0.27413836,-0.22494304,0.032986216,-0.28423727,-0.33139515,-0.09869778,0.17597698,-0.16887054,-0.21401289,-0.25753736,0.15112543,0.023972075,0.37046728,0.21629706,0.40219778,0.06816418,0.14329015,0.09809614,0.3992585,0.5011368,-4.5969725,0.75547093,-0.06355125,0.18326792,-0.041363247,-0.24465021,-0.8623926,0.17301399,-0.09849941,-0.53036416,0.6462284,0.38638875,0.08001809,0.40502757,-0.6277967,-0.44861352,-0.3408397,-0.08925553,0.13047035,0.41407132,0.45806012,-0.0099267755,-0.008533685,-0.2228038,-0.5087038,-0.35945162,0.12346778,-0.31421813,-0.046587918,0.3652729,0.343849,0.15559974,0.22924842,0.09493283,-0.3295756,-0.38267168,0.3231455,0.15582775,-0.2895788,0.026201006,-0.21975787,0.7673766,-0.14900723,0.30338874,0.04127737,0.41617587,-0.024255987,0.0975073,-0.17170201,0.034878686,-0.20119959,0.6262282,0.29345882,0.3533627,-0.37463093,-0.49776304,-0.056332882,0.2454493,0.3186758,0.1511974,0.94643575,0.08207633,-0.41031232,0.4341425,0.40196165,0.5684219,-0.17576161,0.0806694,-0.43564922,-0.1440254,0.15838647,0.047324818,0.6022106,-0.010507457,0.15598106,-0.38182357,-0.09522074,0.54245657,-0.31707615,-0.5793624,0.19613895,-0.04021707,-0.06586403,-0.64439917,0.42087454,0.14645846,0.65180016,-0.33038333,0.3100433,0.0072843954,0.25805962,0.3925261,-0.18759727,0.71574265,0.22553483,0.6025152,-0.59573996,0.42026138,-0.16755693,-0.02248032,0.0692906,0.11753483,0.66663563,0.5382502,0.25330344,-0.05206476,-0.6454575,0.19297871,-0.09037291,-0.21462667,0.62719274,-0.21673453,-0.45110026,0.07571334,-0.5426903,-0.47413558,-0.179686,0.13525026,0.042764213,0.31032553,0.1774424,-0.1215862,-0.24403748,0.4111866,0.48866293,0.21351507,0.27289438,0.32167256,0.09542343,-0.595492,0.54177094,-0.18351838,0.37929836,0.163471,0.33334187,0.07938986,0.20823926,0.42874694,0.11846483,-0.5688696,0.08533045,0.3649624,0.50403607,0.10192044,-0.25093743,0.1052467,-0.12651944,-0.054146297,0.06920721,-0.008694626,0.290604,-0.122868486,0.32107243,-0.20358294,-0.053622633,0.4765737,-0.35426304,0.1458384,-0.0993504,-0.07083378,0.29238084,-0.21717934,0.60992247,0.3481273,-0.14956586,0.17279924,-0.6319041,0.17641029,-0.51363575,0.28299004,-0.16861513,-0.3874343,-0.72754216,0.82732296,-0.17627628,0.09149102,-0.02343698,-0.3080591,-0.49526447,-0.06989376,-0.21805848,0.055295054,0.15491296,0.53886265,0.3143052,-0.5228653,-0.35953614,-0.38140583,0.21408239,0.49864456,-0.030516397,-0.48313934,-0.022087904,-0.05568235,2.0207489,-0.2160857,-0.43779862,-0.31972802,0.23226906,-0.17046955,-0.18044934,0.7649414,-0.089306936,0.07394132,-0.26322138,-0.6307834,0.2539898,0.08996068,-0.34500092,0.33602887,-0.13221185,0.28053254,-0.25021264,-0.30681622,-0.33562735,0.18290497,0.06822218,-0.24495289,0.05461444,0.33336857,0.76538455,0.20373443,0.016385894,-0.16068989,0.17400645,0.14975803,-0.42380872,-0.003601376,0.17884707,1.2290605,-0.2186237,-0.46154222,0.7431659,-0.05445029,0.5774843,0.3956832,0.23814428,-0.2812907,0.15870173,0.0129259555,-0.09316196,0.065467514,-0.06896221,0.026413985,-0.12827843,0.014654305,0.41048142,-0.14720379,-0.37667838,0.027451046,-0.12152964,0.26416403,0.28160268,0.23450102,-0.3341591,-0.30966365,-0.13191213,-0.07734138,0.002274599,-0.1743013,-0.035735544,-0.66759217,-0.15315521,0.4047766,0.27331066,0.45410213,-0.07747422,-0.4229227,0.7933408,-0.34510672,0.2842102,0.18592218,-0.33156383,0.033438884,-0.07490185,0.84101474,-0.08148291,-0.16623247,0.15557177,0.22915289,0.22836028,0.06738523,-0.17436805,-0.2939907,0.38458773,-0.0057505034,0.51883817,0.19778205,0.17607214,0.3488956,-0.033568144,0.021691527,-0.09033627,-0.21192548,0.5457831,0.4096122,-0.17390896,0.020136667,-0.944774,-0.4868942,-0.3688516,-0.17969409,-0.5807882,0.49534693,0.7260813,-0.475246,0.55481255,0.0076987334,-0.08645997,0.51659465,1.1507825,-0.28258285,0.061819617,-0.19862434,-0.1745369,0.23604488,0.089901835,-0.26777384,0.4513617,0.35737467,0.39011404,0.09260182,-0.48133546,0.522975,-0.2992268,0.17347705,-0.32055497,-0.48377556,0.5026247,0.31763107,0.3303255,0.95727265,-0.039339595,-0.09222102,0.078060515,0.3191415,-1.5649279,-0.20364322,-0.3458002,-0.758231,1.1920285,-0.20660472,-0.30822554,0.10613924,-0.22837426,0.51440763,0.51128757,-0.015207093,0.12476305,0.16690975,0.3347225,0.24871522,-0.40635332,-0.23971923,0.22209892,-0.44992763,0.21512324,0.014755411,0.03404636,0.3574205,0.18449761,0.31495175,-0.25412256,-0.089687645,-0.49687606,0.117575295,0.3049805,-0.058082182,0.0249898,0.52463275,-0.22057097,-0.12716003,0.43246794,0.23366824,0.061985157,-0.097914614,0.022793965,-0.13284568,-0.05361539,0.21470663,0.20947483,-0.45356306,0.33313045,0.02099111,-0.524079,0.68574697,-0.6208377,-0.33035585,0.122387536,-0.2603395,0.2796695,-0.03826021,-0.13568372,0.48312622,0.3319204,0.21138054,-0.04836049,-0.4100653,0.2333822,-0.13193624,0.12242808,-0.3723574,-0.38414568,-0.3888912,-0.22578871,-0.42669374,0.3577011,0.19324231,-0.23342574,0.31559953,-0.12295563,0.21255888,-0.31488445,-0.18625,-0.13673405,0.1122016,0.22065118,0.15123624,0.19029641,0.3484959]"}', '{"status": false, "created_at": "2026-05-27T07:28:40.593283", "created_by": 138222, "is_deleted": true, "product_id": 10, "category_id": 17, "product_name": "test shoe", "product_image": "1779841720_master_6a163ab890dae.jpg", "image_embedding": "[-0.28687155,-0.009447496,0.24155691,0.20169702,-0.35329565,-0.010317312,-0.12017762,-0.055725444,0.19819468,-0.40619698,0.13760765,-0.14388211,-0.67398244,-0.09475455,-0.44127932,0.24134818,1.3526431,0.23980545,0.16561377,0.0008125007,-0.5904113,-0.16738696,0.16733946,-0.3290499,-0.20856722,0.47121018,0.21269485,0.0811219,-0.23460868,0.097190015,0.023761392,-0.095907055,0.06723145,0.26279277,-0.57392526,0.16463691,0.46803504,0.819545,-0.014781628,1.164246,-0.30374315,-0.59751004,0.11246516,-0.5879108,0.36508694,-0.28567877,0.06740277,-0.11298322,-0.04736208,-0.2644331,0.31149226,0.29083663,0.11263322,-0.18946585,-0.47344953,0.3526506,0.13888255,0.3359523,-0.4627412,0.06055145,0.81667244,-0.012488436,0.6090824,-0.5491482,0.4200054,0.04399846,0.33345553,0.608736,-0.21371505,-0.13719973,-0.2695019,-0.13884431,0.27413836,-0.22494304,0.032986216,-0.28423727,-0.33139515,-0.09869778,0.17597698,-0.16887054,-0.21401289,-0.25753736,0.15112543,0.023972075,0.37046728,0.21629706,0.40219778,0.06816418,0.14329015,0.09809614,0.3992585,0.5011368,-4.5969725,0.75547093,-0.06355125,0.18326792,-0.041363247,-0.24465021,-0.8623926,0.17301399,-0.09849941,-0.53036416,0.6462284,0.38638875,0.08001809,0.40502757,-0.6277967,-0.44861352,-0.3408397,-0.08925553,0.13047035,0.41407132,0.45806012,-0.0099267755,-0.008533685,-0.2228038,-0.5087038,-0.35945162,0.12346778,-0.31421813,-0.046587918,0.3652729,0.343849,0.15559974,0.22924842,0.09493283,-0.3295756,-0.38267168,0.3231455,0.15582775,-0.2895788,0.026201006,-0.21975787,0.7673766,-0.14900723,0.30338874,0.04127737,0.41617587,-0.024255987,0.0975073,-0.17170201,0.034878686,-0.20119959,0.6262282,0.29345882,0.3533627,-0.37463093,-0.49776304,-0.056332882,0.2454493,0.3186758,0.1511974,0.94643575,0.08207633,-0.41031232,0.4341425,0.40196165,0.5684219,-0.17576161,0.0806694,-0.43564922,-0.1440254,0.15838647,0.047324818,0.6022106,-0.010507457,0.15598106,-0.38182357,-0.09522074,0.54245657,-0.31707615,-0.5793624,0.19613895,-0.04021707,-0.06586403,-0.64439917,0.42087454,0.14645846,0.65180016,-0.33038333,0.3100433,0.0072843954,0.25805962,0.3925261,-0.18759727,0.71574265,0.22553483,0.6025152,-0.59573996,0.42026138,-0.16755693,-0.02248032,0.0692906,0.11753483,0.66663563,0.5382502,0.25330344,-0.05206476,-0.6454575,0.19297871,-0.09037291,-0.21462667,0.62719274,-0.21673453,-0.45110026,0.07571334,-0.5426903,-0.47413558,-0.179686,0.13525026,0.042764213,0.31032553,0.1774424,-0.1215862,-0.24403748,0.4111866,0.48866293,0.21351507,0.27289438,0.32167256,0.09542343,-0.595492,0.54177094,-0.18351838,0.37929836,0.163471,0.33334187,0.07938986,0.20823926,0.42874694,0.11846483,-0.5688696,0.08533045,0.3649624,0.50403607,0.10192044,-0.25093743,0.1052467,-0.12651944,-0.054146297,0.06920721,-0.008694626,0.290604,-0.122868486,0.32107243,-0.20358294,-0.053622633,0.4765737,-0.35426304,0.1458384,-0.0993504,-0.07083378,0.29238084,-0.21717934,0.60992247,0.3481273,-0.14956586,0.17279924,-0.6319041,0.17641029,-0.51363575,0.28299004,-0.16861513,-0.3874343,-0.72754216,0.82732296,-0.17627628,0.09149102,-0.02343698,-0.3080591,-0.49526447,-0.06989376,-0.21805848,0.055295054,0.15491296,0.53886265,0.3143052,-0.5228653,-0.35953614,-0.38140583,0.21408239,0.49864456,-0.030516397,-0.48313934,-0.022087904,-0.05568235,2.0207489,-0.2160857,-0.43779862,-0.31972802,0.23226906,-0.17046955,-0.18044934,0.7649414,-0.089306936,0.07394132,-0.26322138,-0.6307834,0.2539898,0.08996068,-0.34500092,0.33602887,-0.13221185,0.28053254,-0.25021264,-0.30681622,-0.33562735,0.18290497,0.06822218,-0.24495289,0.05461444,0.33336857,0.76538455,0.20373443,0.016385894,-0.16068989,0.17400645,0.14975803,-0.42380872,-0.003601376,0.17884707,1.2290605,-0.2186237,-0.46154222,0.7431659,-0.05445029,0.5774843,0.3956832,0.23814428,-0.2812907,0.15870173,0.0129259555,-0.09316196,0.065467514,-0.06896221,0.026413985,-0.12827843,0.014654305,0.41048142,-0.14720379,-0.37667838,0.027451046,-0.12152964,0.26416403,0.28160268,0.23450102,-0.3341591,-0.30966365,-0.13191213,-0.07734138,0.002274599,-0.1743013,-0.035735544,-0.66759217,-0.15315521,0.4047766,0.27331066,0.45410213,-0.07747422,-0.4229227,0.7933408,-0.34510672,0.2842102,0.18592218,-0.33156383,0.033438884,-0.07490185,0.84101474,-0.08148291,-0.16623247,0.15557177,0.22915289,0.22836028,0.06738523,-0.17436805,-0.2939907,0.38458773,-0.0057505034,0.51883817,0.19778205,0.17607214,0.3488956,-0.033568144,0.021691527,-0.09033627,-0.21192548,0.5457831,0.4096122,-0.17390896,0.020136667,-0.944774,-0.4868942,-0.3688516,-0.17969409,-0.5807882,0.49534693,0.7260813,-0.475246,0.55481255,0.0076987334,-0.08645997,0.51659465,1.1507825,-0.28258285,0.061819617,-0.19862434,-0.1745369,0.23604488,0.089901835,-0.26777384,0.4513617,0.35737467,0.39011404,0.09260182,-0.48133546,0.522975,-0.2992268,0.17347705,-0.32055497,-0.48377556,0.5026247,0.31763107,0.3303255,0.95727265,-0.039339595,-0.09222102,0.078060515,0.3191415,-1.5649279,-0.20364322,-0.3458002,-0.758231,1.1920285,-0.20660472,-0.30822554,0.10613924,-0.22837426,0.51440763,0.51128757,-0.015207093,0.12476305,0.16690975,0.3347225,0.24871522,-0.40635332,-0.23971923,0.22209892,-0.44992763,0.21512324,0.014755411,0.03404636,0.3574205,0.18449761,0.31495175,-0.25412256,-0.089687645,-0.49687606,0.117575295,0.3049805,-0.058082182,0.0249898,0.52463275,-0.22057097,-0.12716003,0.43246794,0.23366824,0.061985157,-0.097914614,0.022793965,-0.13284568,-0.05361539,0.21470663,0.20947483,-0.45356306,0.33313045,0.02099111,-0.524079,0.68574697,-0.6208377,-0.33035585,0.122387536,-0.2603395,0.2796695,-0.03826021,-0.13568372,0.48312622,0.3319204,0.21138054,-0.04836049,-0.4100653,0.2333822,-0.13193624,0.12242808,-0.3723574,-0.38414568,-0.3888912,-0.22578871,-0.42669374,0.3577011,0.19324231,-0.23342574,0.31559953,-0.12295563,0.21255888,-0.31488445,-0.18625,-0.13673405,0.1122016,0.22065118,0.15123624,0.19029641,0.3484959]"}', '2026-06-02 04:30:14.068432');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (39, 138222, 'UPDATE', 'categories', 17, '{"logo": "brand_test_1780349251.jpg", "status": true, "created_at": "2026-05-23T00:50:59.71931", "created_by": 138222, "is_deleted": false, "category_id": 17, "description": null, "category_name": "brand_test"}', '{"logo": "brand_test_1780349251.jpg", "status": false, "created_at": "2026-05-23T00:50:59.71931", "created_by": 138222, "is_deleted": true, "category_id": 17, "description": null, "category_name": "brand_test"}', '2026-06-02 04:30:18.527117');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (40, 138222, 'INSERT', 'tickets', 23, NULL, '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 23, "batch_code": "LH01", "created_at": "2026-06-02T04:32:27.121314", "is_deleted": false, "manager_id": 138222, "ticket_code": "PN-260602-0001", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:32:27.121314');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (41, 138222, 'INSERT', 'ticket_details', 40, NULL, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 10, "detail_id": 40, "ticket_id": 23, "created_at": "2026-06-02T04:32:27.121314", "variant_id": 12, "processed_qty": 0}', '2026-06-02 04:32:27.121314');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (42, 138222, 'INSERT', 'ticket_details', 41, NULL, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 10, "detail_id": 41, "ticket_id": 23, "created_at": "2026-06-02T04:32:27.121314", "variant_id": 3, "processed_qty": 0}', '2026-06-02 04:32:27.121314');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (44, 333011, 'UPDATE', 'tickets', 23, '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 23, "batch_code": "LH01", "created_at": "2026-06-02T04:32:27.121314", "is_deleted": false, "manager_id": 138222, "ticket_code": "PN-260602-0001", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 23, "batch_code": "LH01", "created_at": "2026-06-02T04:32:27.121314", "is_deleted": false, "manager_id": 138222, "ticket_code": "PN-260602-0001", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:32:33.898619');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (45, 333011, 'UPDATE', 'ticket_details', 40, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 10, "detail_id": 40, "ticket_id": 23, "created_at": "2026-06-02T04:32:27.121314", "variant_id": 12, "processed_qty": 0}', '{"note": "", "is_diff": false, "qr_code": "https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=23&vid=12", "quantity": 10, "detail_id": 40, "ticket_id": 23, "created_at": "2026-06-02T04:32:27.121314", "variant_id": 12, "processed_qty": 10}', '2026-06-02 04:32:53.553586');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (46, 333011, 'UPDATE', 'ticket_details', 42, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 10, "detail_id": 42, "ticket_id": 23, "created_at": "2026-06-02T04:32:27.121314", "variant_id": 20, "processed_qty": 0}', '{"note": "", "is_diff": false, "qr_code": "https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=23&vid=20", "quantity": 10, "detail_id": 42, "ticket_id": 23, "created_at": "2026-06-02T04:32:27.121314", "variant_id": 20, "processed_qty": 10}', '2026-06-02 04:33:08.050471');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (47, 333011, 'UPDATE', 'ticket_details', 41, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 10, "detail_id": 41, "ticket_id": 23, "created_at": "2026-06-02T04:32:27.121314", "variant_id": 3, "processed_qty": 0}', '{"note": "", "is_diff": false, "qr_code": "https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=23&vid=3", "quantity": 10, "detail_id": 41, "ticket_id": 23, "created_at": "2026-06-02T04:32:27.121314", "variant_id": 3, "processed_qty": 10}', '2026-06-02 04:33:25.196217');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (48, 333011, 'UPDATE', 'product_variants', 12, '{"sku": "JO-J1RHOPB-BLA-41", "size": "41", "color": "BLACK", "stock": 0, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 5, "variant_id": 12, "reserved_stock": 0}', '{"sku": "JO-J1RHOPB-BLA-41", "size": "41", "color": "BLACK", "stock": 10, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 5, "variant_id": 12, "reserved_stock": 0}', '2026-06-02 04:33:27.587086');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (49, 333011, 'UPDATE', 'product_variants', 20, '{"sku": "NI-NBPL-BLU-40", "size": "40", "color": "BLUE", "stock": 0, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 2, "variant_id": 20, "reserved_stock": 0}', '{"sku": "NI-NBPL-BLU-40", "size": "40", "color": "BLUE", "stock": 10, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 2, "variant_id": 20, "reserved_stock": 0}', '2026-06-02 04:33:27.587086');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (50, 333011, 'UPDATE', 'product_variants', 3, '{"sku": "VA-VOSBAW-BLA-41", "size": "41", "color": "BLACK", "stock": 4, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 7, "variant_id": 3, "reserved_stock": 0}', '{"sku": "VA-VOSBAW-BLA-41", "size": "41", "color": "BLACK", "stock": 14, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 7, "variant_id": 3, "reserved_stock": 0}', '2026-06-02 04:33:27.587086');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (51, 333011, 'UPDATE', 'tickets', 23, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 23, "batch_code": "LH01", "created_at": "2026-06-02T04:32:27.121314", "is_deleted": false, "manager_id": 138222, "ticket_code": "PN-260602-0001", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "COMPLETED", "is_diff": false, "staff_id": 333011, "ticket_id": 23, "batch_code": "LH01", "created_at": "2026-06-02T04:32:27.121314", "is_deleted": false, "manager_id": 138222, "ticket_code": "PN-260602-0001", "ticket_type": "IMPORT", "completed_at": "2026-06-02T04:33:27.587086"}', '2026-06-02 04:33:27.587086');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (52, 138222, 'UPDATE', 'categories', 1, '{"logo": "logo_nike.jpg", "status": true, "created_at": "2026-03-31T13:12:46.232382", "created_by": 138222, "is_deleted": false, "category_id": 1, "description": "Nike brand shoes", "category_name": "Nike"}', '{"logo": "nike_1780349655.jpg", "status": true, "created_at": "2026-03-31T13:12:46.232382", "created_by": 138222, "is_deleted": false, "category_id": 1, "description": "Nike brand shoes", "category_name": "Nike"}', '2026-06-02 04:34:15.461573');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (53, 138222, 'INSERT', 'tickets', 24, NULL, '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 24, "batch_code": "LH-260602-003", "created_at": "2026-06-02T04:35:24.966762", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0003", "ticket_type": "EXPORT", "completed_at": null}', '2026-06-02 04:35:24.966762');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (54, 138222, 'INSERT', 'ticket_details', 43, NULL, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 5, "detail_id": 43, "ticket_id": 24, "created_at": "2026-06-02T04:35:24.966762", "variant_id": 14, "processed_qty": 0}', '2026-06-02 04:35:24.966762');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (55, 138222, 'UPDATE', 'product_variants', 14, '{"sku": "AD-ASV-BLA-40", "size": "40", "color": "BLACK", "stock": 5, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 4, "variant_id": 14, "reserved_stock": 0}', '{"sku": "AD-ASV-BLA-40", "size": "40", "color": "BLACK", "stock": 5, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 4, "variant_id": 14, "reserved_stock": 5}', '2026-06-02 04:35:24.966762');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (56, 333011, 'UPDATE', 'tickets', 24, '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 24, "batch_code": "LH-260602-003", "created_at": "2026-06-02T04:35:24.966762", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0003", "ticket_type": "EXPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 24, "batch_code": "LH-260602-003", "created_at": "2026-06-02T04:35:24.966762", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0003", "ticket_type": "EXPORT", "completed_at": null}', '2026-06-02 04:35:29.024171');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (57, 333011, 'UPDATE', 'ticket_details', 43, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 5, "detail_id": 43, "ticket_id": 24, "created_at": "2026-06-02T04:35:24.966762", "variant_id": 14, "processed_qty": 0}', '{"note": "[{\"shelf_id\":\"1\",\"slot_code\":\"2-03\",\"qty\":1},{\"shelf_id\":\"1\",\"slot_code\":\"2-04\",\"qty\":4}]", "is_diff": false, "qr_code": null, "quantity": 5, "detail_id": 43, "ticket_id": 24, "created_at": "2026-06-02T04:35:24.966762", "variant_id": 14, "processed_qty": 5}', '2026-06-02 04:35:36.750246');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (59, 333011, 'UPDATE', 'tickets', 24, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 24, "batch_code": "LH-260602-003", "created_at": "2026-06-02T04:35:24.966762", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0003", "ticket_type": "EXPORT", "completed_at": null}', '{"status": "COMPLETED", "is_diff": false, "staff_id": 333011, "ticket_id": 24, "batch_code": "LH-260602-003", "created_at": "2026-06-02T04:35:24.966762", "is_deleted": false, "manager_id": 138222, "ticket_code": "PX-260602-0003", "ticket_type": "EXPORT", "completed_at": "2026-06-02T04:35:38.304946"}', '2026-06-02 04:35:38.304946');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (60, 118999, 'INSERT', 'tickets', 25, NULL, '{"status": "PENDING", "is_diff": false, "staff_id": 711594, "ticket_id": 25, "batch_code": "LH02", "created_at": "2026-06-02T04:36:51.623727", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0002", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:36:51.623727');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (61, 118999, 'INSERT', 'ticket_details', 44, NULL, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 20, "detail_id": 44, "ticket_id": 25, "created_at": "2026-06-02T04:36:51.623727", "variant_id": 14, "processed_qty": 0}', '2026-06-02 04:36:51.623727');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (62, 711594, 'UPDATE', 'tickets', 25, '{"status": "PENDING", "is_diff": false, "staff_id": 711594, "ticket_id": 25, "batch_code": "LH02", "created_at": "2026-06-02T04:36:51.623727", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0002", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 711594, "ticket_id": 25, "batch_code": "LH02", "created_at": "2026-06-02T04:36:51.623727", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0002", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:37:32.026918');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (63, 711594, 'UPDATE', 'ticket_details', 44, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 20, "detail_id": 44, "ticket_id": 25, "created_at": "2026-06-02T04:36:51.623727", "variant_id": 14, "processed_qty": 0}', '{"note": "", "is_diff": false, "qr_code": "https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=25&vid=14", "quantity": 20, "detail_id": 44, "ticket_id": 25, "created_at": "2026-06-02T04:36:51.623727", "variant_id": 14, "processed_qty": 20}', '2026-06-02 04:38:00.241825');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (64, 711594, 'UPDATE', 'product_variants', 14, '{"sku": "AD-ASV-BLA-40", "size": "40", "color": "BLACK", "stock": 0, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 4, "variant_id": 14, "reserved_stock": 0}', '{"sku": "AD-ASV-BLA-40", "size": "40", "color": "BLACK", "stock": 20, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 4, "variant_id": 14, "reserved_stock": 0}', '2026-06-02 04:38:04.624524');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (65, 711594, 'UPDATE', 'tickets', 25, '{"status": "PROCESSING", "is_diff": false, "staff_id": 711594, "ticket_id": 25, "batch_code": "LH02", "created_at": "2026-06-02T04:36:51.623727", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0002", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "COMPLETED", "is_diff": false, "staff_id": 711594, "ticket_id": 25, "batch_code": "LH02", "created_at": "2026-06-02T04:36:51.623727", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0002", "ticket_type": "IMPORT", "completed_at": "2026-06-02T04:38:04.624524"}', '2026-06-02 04:38:04.624524');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (66, 711594, 'UPDATE', 'users', 711594, '{"role": "STAFF", "status": true, "address": "789 Trần Hưng Đạo, Quận Sơn Trà, Đà Nẵng", "user_id": 711594, "full_name": "Staff Two", "created_at": "2026-03-31T13:12:46.232382", "is_deleted": false, "phone_number": "0987654321", "password_hash": "$2y$10$f86nxwquQB5QMKrWUfb1pudKTVmUwtZxi2ClDlvxtnbaWNQJw2ene"}', '{"role": "STAFF", "status": true, "address": "789 Trần Hưng Đạo, Quận Sơn Trà, Đà Nẵng", "user_id": 711594, "full_name": "Nguyễn Văn Mạnh", "created_at": "2026-03-31T13:12:46.232382", "is_deleted": false, "phone_number": "0987654321", "password_hash": "$2y$10$f86nxwquQB5QMKrWUfb1pudKTVmUwtZxi2ClDlvxtnbaWNQJw2ene"}', '2026-06-02 04:39:20.383528');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (67, 333011, 'UPDATE', 'users', 333011, '{"role": "STAFF", "status": true, "address": "85 Nguyễn Huệ, Quận Ninh Kiều, Cần Thơ", "user_id": 333011, "full_name": "Staff One", "created_at": "2026-03-31T13:12:46.232382", "is_deleted": false, "phone_number": "0901234500", "password_hash": "$2y$10$MIlF4hYtfwwlUYGyvv7.T.Ki2xiXFPPNOiWI.zXMVomiKf9YvoTa6"}', '{"role": "STAFF", "status": true, "address": "85 Nguyễn Huệ, Quận Ninh Kiều, Cần Thơ", "user_id": 333011, "full_name": "Trần Thị Yến", "created_at": "2026-03-31T13:12:46.232382", "is_deleted": false, "phone_number": "0901234500", "password_hash": "$2y$10$MIlF4hYtfwwlUYGyvv7.T.Ki2xiXFPPNOiWI.zXMVomiKf9YvoTa6"}', '2026-06-02 04:40:04.835959');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (68, 118999, 'INSERT', 'tickets', 26, NULL, '{"status": "PENDING", "is_diff": false, "staff_id": 711594, "ticket_id": 26, "batch_code": "LH03", "created_at": "2026-06-02T04:49:25.953708", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0003", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:49:25.953708');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (69, 118999, 'INSERT', 'ticket_details', 45, NULL, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 5, "detail_id": 45, "ticket_id": 26, "created_at": "2026-06-02T04:49:25.953708", "variant_id": 7, "processed_qty": 0}', '2026-06-02 04:49:25.953708');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (70, 118999, 'UPDATE', 'tickets', 26, '{"status": "PENDING", "is_diff": false, "staff_id": 711594, "ticket_id": 26, "batch_code": "LH03", "created_at": "2026-06-02T04:49:25.953708", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0003", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PENDING", "is_diff": false, "staff_id": 711594, "ticket_id": 26, "batch_code": "LH03", "created_at": "2026-06-02T04:49:25.953708", "is_deleted": true, "manager_id": 118999, "ticket_code": "PN-260602-0003", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:49:51.093941');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (71, 118999, 'INSERT', 'tickets', 27, NULL, '{"status": "PENDING", "is_diff": false, "staff_id": 711594, "ticket_id": 27, "batch_code": "LH04", "created_at": "2026-06-02T04:50:10.670753", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0004", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:50:10.670753');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (72, 118999, 'INSERT', 'ticket_details', 46, NULL, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 8, "detail_id": 46, "ticket_id": 27, "created_at": "2026-06-02T04:50:10.670753", "variant_id": 7, "processed_qty": 0}', '2026-06-02 04:50:10.670753');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (73, 118999, 'UPDATE', 'tickets', 27, '{"status": "PENDING", "is_diff": false, "staff_id": 711594, "ticket_id": 27, "batch_code": "LH04", "created_at": "2026-06-02T04:50:10.670753", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0004", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 27, "batch_code": "LH04", "created_at": "2026-06-02T04:50:10.670753", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0004", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:50:16.800757');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (74, 333011, 'UPDATE', 'tickets', 27, '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 27, "batch_code": "LH04", "created_at": "2026-06-02T04:50:10.670753", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0004", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 27, "batch_code": "LH04", "created_at": "2026-06-02T04:50:10.670753", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0004", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:50:25.645688');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (75, 333011, 'UPDATE', 'ticket_details', 46, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 8, "detail_id": 46, "ticket_id": 27, "created_at": "2026-06-02T04:50:10.670753", "variant_id": 7, "processed_qty": 0}', '{"note": "dư 1 Jordan 1 Retro Low OG SP Travis Scott Black Phantom", "is_diff": true, "qr_code": "https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=27&vid=7", "quantity": 8, "detail_id": 46, "ticket_id": 27, "created_at": "2026-06-02T04:50:10.670753", "variant_id": 7, "processed_qty": 9}', '2026-06-02 04:51:00.798459');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (76, 333011, 'UPDATE', 'product_variants', 7, '{"sku": "JO-J1RLOSTSBP-BLA-40", "size": "40", "color": "BLACK", "stock": 0, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 6, "variant_id": 7, "reserved_stock": 0}', '{"sku": "JO-J1RLOSTSBP-BLA-40", "size": "40", "color": "BLACK", "stock": 9, "status": true, "created_at": "2026-05-28T01:34:20.924002", "is_deleted": false, "product_id": 6, "variant_id": 7, "reserved_stock": 0}', '2026-06-02 04:51:22.170479');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (77, 333011, 'UPDATE', 'tickets', 27, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 27, "batch_code": "LH04", "created_at": "2026-06-02T04:50:10.670753", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0004", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "COMPLETE_DIFF", "is_diff": true, "staff_id": 333011, "ticket_id": 27, "batch_code": "LH04", "created_at": "2026-06-02T04:50:10.670753", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0004", "ticket_type": "IMPORT", "completed_at": "2026-06-02T04:51:22.170479"}', '2026-06-02 04:51:22.170479');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (78, 118999, 'INSERT', 'tickets', 28, NULL, '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:57:34.289679');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (79, 118999, 'INSERT', 'ticket_details', 47, NULL, '{"note": null, "is_diff": false, "qr_code": null, "quantity": 1, "detail_id": 47, "ticket_id": 28, "created_at": "2026-06-02T04:57:34.289679", "variant_id": 24, "processed_qty": 0}', '2026-06-02 04:57:34.289679');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (80, 333011, 'UPDATE', 'tickets', 28, '{"status": "PENDING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:57:43.827671');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (81, 333011, 'UPDATE', 'tickets', 28, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PAUSED", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:57:48.133459');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (82, 333011, 'UPDATE', 'tickets', 28, '{"status": "PAUSED", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:57:51.847724');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (83, 333011, 'UPDATE', 'tickets', 28, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:58:57.591505');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (84, 333011, 'UPDATE', 'tickets', 28, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 04:59:37.918024');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (85, 333011, 'UPDATE', 'tickets', 28, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 05:02:27.026285');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (86, 333011, 'UPDATE', 'tickets', 28, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 05:02:47.958184');
INSERT INTO public.system_audit_logs (audit_id, user_id, action_type, table_name, target_id, old_data, new_data, created_at) VALUES (87, 333011, 'UPDATE', 'tickets', 28, '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '{"status": "PROCESSING", "is_diff": false, "staff_id": 333011, "ticket_id": 28, "batch_code": "LH05", "created_at": "2026-06-02T04:57:34.289679", "is_deleted": false, "manager_id": 118999, "ticket_code": "PN-260602-0005", "ticket_type": "IMPORT", "completed_at": null}', '2026-06-02 05:03:09.763011');


--
-- TOC entry 5311 (class 0 OID 51232)
-- Dependencies: 257
-- Data for Name: ticket_details; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (1, 1, 11, 10, 10, '2026-04-21 23:58:18.695856', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=11', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (2, 1, 15, 5, 5, '2026-04-21 23:58:18.695856', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=15', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (3, 2, 19, 8, 8, '2026-05-15 21:02:04.60913', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=19', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (4, 2, 1, 15, 15, '2026-05-15 21:02:04.60913', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=1', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (5, 2, 21, 13, 13, '2026-05-15 21:02:04.60913', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=21', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (6, 3, 22, 13, 13, '2026-05-01 21:29:18.536048', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=22', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (7, 3, 15, 14, 14, '2026-05-01 21:29:18.536048', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=15', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (8, 3, 26, 9, 9, '2026-05-01 21:29:18.536048', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=26', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (9, 4, 25, 15, 15, '2026-05-23 08:50:13.230096', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=25', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (10, 4, 9, 10, 10, '2026-05-23 08:50:13.230096', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=9', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (11, 5, 14, 13, 13, '2026-04-26 23:19:40.311915', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=14', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (12, 5, 1, 6, 6, '2026-04-26 23:19:40.311915', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=1', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (13, 6, 17, 14, 14, '2026-04-17 00:39:23.245674', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=17', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (14, 6, 26, 15, 15, '2026-04-17 00:39:23.245674', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=26', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (15, 7, 5, 10, 10, '2026-04-06 01:05:43.228762', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=5', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (16, 7, 26, 12, 12, '2026-04-06 01:05:43.228762', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=26', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (17, 8, 1, 9, 9, '2026-04-22 21:19:06.022039', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=1', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (18, 8, 17, 13, 13, '2026-04-22 21:19:06.022039', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=17', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (19, 9, 6, 11, 11, '2026-05-11 17:00:44.618877', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=6', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (20, 9, 20, 14, 14, '2026-05-11 17:00:44.618877', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=20', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (21, 9, 11, 5, 5, '2026-05-11 17:00:44.618877', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=11', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (22, 10, 27, 6, 6, '2026-05-07 21:32:43.492004', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=27', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (23, 10, 20, 7, 7, '2026-05-07 21:32:43.492004', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?vid=20', NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (24, 11, 28, 5, 5, '2026-05-28 01:44:19.338385', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=11&vid=28', '', false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (25, 12, 17, 4, 4, '2026-05-28 02:17:23.682995', NULL, NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (30, 15, 5, 10, 10, '2026-05-29 01:42:43.765756', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=15&vid=5', '', false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (31, 16, 10, 5, 5, '2026-05-29 03:07:40.561933', NULL, NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (32, 17, 28, 3, 3, '2026-05-29 03:13:41.868046', NULL, NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (34, 18, 16, 3, 3, '2026-05-29 03:41:42.69234', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=18&vid=16', '', false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (35, 18, 13, 2, 1, '2026-05-29 03:41:42.69234', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=18&vid=13', 'thiếu 1 Adidas Superstar Vintage', true);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (33, 18, 3, 3, 4, '2026-05-29 03:41:42.69234', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=18&vid=3', 'dư 1 Vans Old Skool Black and White', true);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (36, 19, 5, 9, 9, '2026-05-29 03:43:38.88557', NULL, NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (37, 20, 21, 5, 5, '2026-05-30 02:04:27.782667', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=20&vid=21', '', false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (38, 21, 5, 2, 2, '2026-06-02 04:17:24.889506', NULL, '[{"shelf_id":"undefined","slot_code":"2-04","qty":1},{"shelf_id":"undefined","slot_code":"2-05","qty":1}]', false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (39, 22, 9, 2, 2, '2026-06-02 04:25:34.11142', NULL, '[{"shelf_id":"1","slot_code":"1-05","qty":2}]', false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (40, 23, 12, 10, 10, '2026-06-02 04:32:27.121314', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=23&vid=12', '', false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (42, 23, 20, 10, 10, '2026-06-02 04:32:27.121314', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=23&vid=20', '', false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (41, 23, 3, 10, 10, '2026-06-02 04:32:27.121314', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=23&vid=3', '', false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (43, 24, 14, 5, 5, '2026-06-02 04:35:24.966762', NULL, '[{"shelf_id":"1","slot_code":"2-03","qty":1},{"shelf_id":"1","slot_code":"2-04","qty":4}]', false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (44, 25, 14, 20, 20, '2026-06-02 04:36:51.623727', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=25&vid=14', '', false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (45, 26, 7, 5, 0, '2026-06-02 04:49:25.953708', NULL, NULL, false);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (46, 27, 7, 8, 9, '2026-06-02 04:50:10.670753', 'https://countless-henna-obtain.ngrok-free.dev/Shoe_Warehouse/check_QR.php?tid=27&vid=7', 'dư 1 Jordan 1 Retro Low OG SP Travis Scott Black Phantom', true);
INSERT INTO public.ticket_details (detail_id, ticket_id, variant_id, quantity, processed_qty, created_at, qr_code, note, is_diff) VALUES (47, 28, 24, 1, 0, '2026-06-02 04:57:34.289679', NULL, NULL, false);


--
-- TOC entry 5313 (class 0 OID 51242)
-- Dependencies: 259
-- Data for Name: ticket_import_temp; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- TOC entry 5315 (class 0 OID 51253)
-- Dependencies: 261
-- Data for Name: tickets; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (1, 'PN-260421-0001', 'IMPORT', 'COMPLETED', 138222, 333011, '2026-04-21 23:58:18.695856', 'LH01', '2026-04-22 01:43:45.079654', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (2, 'PN-260515-0002', 'IMPORT', 'COMPLETED', 138222, 711594, '2026-05-15 21:02:04.60913', 'LH01', '2026-05-15 22:30:14.990608', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (3, 'PN-260501-0003', 'IMPORT', 'COMPLETED', 138222, 333011, '2026-05-01 21:29:18.536048', 'LH01', '2026-05-01 22:12:42.616954', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (4, 'PN-260523-0004', 'IMPORT', 'COMPLETED', 138222, 333011, '2026-05-23 08:50:13.230096', 'LH01', '2026-05-23 10:05:49.826006', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (5, 'PN-260426-0005', 'IMPORT', 'COMPLETED', 138222, 333011, '2026-04-26 23:19:40.311915', 'LH01', '2026-04-27 01:28:19.535086', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (6, 'PN-260417-0006', 'IMPORT', 'COMPLETED', 138222, 333011, '2026-04-17 00:39:23.245674', 'LH01', '2026-04-17 02:57:56.05417', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (7, 'PN-260406-0007', 'IMPORT', 'COMPLETED', 138222, 333011, '2026-04-06 01:05:43.228762', 'LH01', '2026-04-06 02:59:54.098156', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (8, 'PN-260422-0008', 'IMPORT', 'COMPLETED', 138222, 333011, '2026-04-22 21:19:06.022039', 'LH01', '2026-04-22 23:03:28.658471', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (9, 'PN-260511-0009', 'IMPORT', 'COMPLETED', 138222, 333011, '2026-05-11 17:00:44.618877', 'LH01', '2026-05-11 19:26:44.350648', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (10, 'PN-260507-0010', 'IMPORT', 'COMPLETED', 138222, 711594, '2026-05-07 21:32:43.492004', 'LH01', '2026-05-07 23:25:36.12219', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (11, 'PN-260528-0001', 'IMPORT', 'COMPLETED', 138222, 333011, '2026-05-28 01:44:19.338385', 'LH01', '2026-05-28 01:44:55.234731', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (12, 'PX-260528-0001', 'EXPORT', 'COMPLETED', 138222, 333011, '2026-05-28 02:17:23.682995', 'LH01', '2026-05-28 02:17:36.992691', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (15, 'PN-260529-0001', 'IMPORT', 'COMPLETED', 118999, 333011, '2026-05-29 01:42:43.765756', 'LH01', '2026-05-29 01:48:44.41457', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (16, 'PX-260529-0001', 'EXPORT', 'COMPLETED', 118999, 333011, '2026-05-29 03:07:40.561933', 'LH01', '2026-05-29 03:08:31.290236', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (17, 'PX-260529-0002', 'EXPORT', 'COMPLETED', 118999, 333011, '2026-05-29 03:13:41.868046', 'LH02', '2026-05-29 03:24:07.961305', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (18, 'PN-260529-0002', 'IMPORT', 'COMPLETE_DIFF', 118999, 333011, '2026-05-29 03:41:42.69234', 'LH02', '2026-05-29 03:43:00.637225', false, true);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (19, 'PX-260529-0003', 'EXPORT', 'COMPLETED', 118999, 333011, '2026-05-29 03:43:38.88557', 'LH03', '2026-05-29 03:43:55.5199', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (20, 'PN-260530-0001', 'IMPORT', 'COMPLETED', 138222, 333011, '2026-05-30 02:04:27.782667', 'LH01', '2026-06-02 04:17:05.719301', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (21, 'PX-260602-0001', 'EXPORT', 'COMPLETED', 138222, 711594, '2026-06-02 04:17:24.889506', 'LH-260602-001', '2026-06-02 04:22:47.220947', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (22, 'PX-260602-0002', 'EXPORT', 'COMPLETED', 138222, 333011, '2026-06-02 04:25:34.11142', 'LH-260602-002', '2026-06-02 04:26:07.573952', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (23, 'PN-260602-0001', 'IMPORT', 'COMPLETED', 138222, 333011, '2026-06-02 04:32:27.121314', 'LH01', '2026-06-02 04:33:27.587086', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (24, 'PX-260602-0003', 'EXPORT', 'COMPLETED', 138222, 333011, '2026-06-02 04:35:24.966762', 'LH-260602-003', '2026-06-02 04:35:38.304946', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (25, 'PN-260602-0002', 'IMPORT', 'COMPLETED', 118999, 711594, '2026-06-02 04:36:51.623727', 'LH02', '2026-06-02 04:38:04.624524', false, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (26, 'PN-260602-0003', 'IMPORT', 'PENDING', 118999, 711594, '2026-06-02 04:49:25.953708', 'LH03', NULL, true, false);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (27, 'PN-260602-0004', 'IMPORT', 'COMPLETE_DIFF', 118999, 333011, '2026-06-02 04:50:10.670753', 'LH04', '2026-06-02 04:51:22.170479', false, true);
INSERT INTO public.tickets (ticket_id, ticket_code, ticket_type, status, manager_id, staff_id, created_at, batch_code, completed_at, is_deleted, is_diff) VALUES (28, 'PN-260602-0005', 'IMPORT', 'PROCESSING', 118999, 333011, '2026-06-02 04:57:34.289679', 'LH05', NULL, false, false);


--
-- TOC entry 5317 (class 0 OID 51263)
-- Dependencies: 263
-- Data for Name: transactions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (1, 'IMPORT', 14, 4, 333011, 'PN-260426-0005', '2026-04-26 11:48:14.040165');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (2, 'IMPORT', 26, 3, 333011, 'PN-260421-0001', '2026-04-09 09:34:17.856497');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (3, 'IMPORT', 11, 4, 333011, 'PN-260515-0002', '2026-05-01 06:04:27.834369');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (4, 'EXPORT', 11, 4, 333011, 'PX-260510-4250', '2026-05-10 19:27:11.557652');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (5, 'IMPORT', 19, 4, 711594, 'PN-260417-0006', '2026-05-08 13:59:38.624361');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (6, 'IMPORT', 22, 1, 711594, 'PN-260523-0004', '2026-05-10 23:27:55.710851');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (7, 'IMPORT', 16, 2, 333011, 'PN-260406-0007', '2026-04-23 13:00:12.032716');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (8, 'IMPORT', 26, 4, 711594, 'PN-260501-0003', '2026-05-22 15:19:06.169872');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (9, 'IMPORT', 26, 3, 333011, 'PN-260417-0006', '2026-04-22 13:50:47.050409');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (10, 'IMPORT', 14, 3, 711594, 'PN-260421-0001', '2026-05-12 01:43:50.324557');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (11, 'IMPORT', 17, 4, 711594, 'PN-260422-0008', '2026-05-16 15:34:11.015776');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (12, 'IMPORT', 22, 4, 333011, 'PN-260406-0007', '2026-05-19 17:54:11.006782');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (13, 'IMPORT', 17, 4, 711594, 'PN-260426-0005', '2026-05-24 02:01:11.356484');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (14, 'IMPORT', 16, 3, 711594, 'PN-260422-0008', '2026-05-15 15:44:51.470437');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (15, 'IMPORT', 11, 3, 711594, 'PN-260507-0010', '2026-05-06 03:24:36.926085');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (16, 'IMPORT', 23, 4, 711594, 'PN-260511-0009', '2026-05-04 08:07:15.054132');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (17, 'IMPORT', 8, 2, 333011, 'PN-260507-0010', '2026-05-23 11:18:05.543863');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (18, 'IMPORT', 15, 2, 333011, 'PN-260422-0008', '2026-05-02 09:23:04.894845');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (19, 'IMPORT', 21, 1, 711594, 'PN-260507-0010', '2026-05-12 07:17:12.436686');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (20, 'IMPORT', 18, 3, 711594, 'PN-260406-0007', '2026-04-24 22:33:02.303989');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (21, 'IMPORT', 25, 2, 333011, 'PN-260426-0005', '2026-05-22 02:53:04.884475');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (22, 'IMPORT', 22, 1, 711594, 'PN-260417-0006', '2026-05-25 12:03:41.28049');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (23, 'EXPORT', 11, 3, 711594, 'PX-260525-2307', '2026-05-25 09:14:10.383587');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (24, 'IMPORT', 8, 3, 333011, 'PN-260507-0010', '2026-04-05 08:59:07.51054');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (25, 'IMPORT', 5, 1, 333011, 'PN-260507-0010', '2026-05-04 09:29:59.123874');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (26, 'EXPORT', 26, 3, 333011, 'PX-260406-5756', '2026-04-06 07:59:27.619498');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (27, 'IMPORT', 5, 2, 711594, 'PN-260523-0004', '2026-05-22 13:24:32.884369');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (28, 'IMPORT', 2, 2, 333011, 'PN-260406-0007', '2026-05-27 20:33:51.518695');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (29, 'IMPORT', 4, 4, 333011, 'PN-260501-0003', '2026-03-31 23:38:32.896231');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (30, 'IMPORT', 19, 4, 711594, 'PN-260422-0008', '2026-04-04 00:25:05.600387');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (31, 'IMPORT', 8, 2, 711594, 'PN-260422-0008', '2026-04-23 10:08:00.67212');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (32, 'EXPORT', 14, 4, 711594, 'PX-260507-8998', '2026-05-07 20:16:32.593578');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (33, 'EXPORT', 18, 1, 711594, 'PX-260522-2621', '2026-05-22 07:54:25.216674');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (34, 'IMPORT', 20, 1, 333011, 'PN-260421-0001', '2026-05-08 01:36:49.926171');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (35, 'IMPORT', 10, 3, 711594, 'PN-260421-0001', '2026-05-06 09:00:46.487089');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (36, 'IMPORT', 27, 2, 333011, 'PN-260406-0007', '2026-04-06 00:18:08.825221');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (37, 'IMPORT', 13, 1, 711594, 'PN-260501-0003', '2026-04-04 04:19:28.133514');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (38, 'IMPORT', 26, 3, 333011, 'PN-260523-0004', '2026-04-20 19:10:08.994714');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (39, 'IMPORT', 6, 3, 711594, 'PN-260501-0003', '2026-05-05 10:02:58.946307');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (40, 'IMPORT', 9, 3, 711594, 'PN-260406-0007', '2026-05-11 20:02:44.876867');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (41, 'EXPORT', 16, 3, 711594, 'PX-260526-2474', '2026-05-26 03:16:46.066612');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (42, 'IMPORT', 17, 3, 711594, 'PN-260422-0008', '2026-05-10 06:26:16.335613');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (43, 'IMPORT', 25, 3, 333011, 'PN-260501-0003', '2026-04-20 01:21:44.265583');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (44, 'IMPORT', 24, 4, 711594, 'PN-260523-0004', '2026-05-01 07:36:07.808485');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (45, 'EXPORT', 20, 1, 711594, 'PX-260330-9516', '2026-03-30 15:43:28.959847');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (46, 'IMPORT', 10, 4, 711594, 'PN-260515-0002', '2026-04-12 13:56:12.149931');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (47, 'IMPORT', 14, 2, 711594, 'PN-260421-0001', '2026-05-05 16:47:10.165279');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (48, 'IMPORT', 27, 4, 333011, 'PN-260426-0005', '2026-05-09 22:08:37.865076');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (49, 'IMPORT', 5, 4, 711594, 'PN-260421-0001', '2026-05-18 10:58:38.377665');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (50, 'IMPORT', 15, 3, 333011, 'PN-260422-0008', '2026-05-17 01:01:54.452136');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (51, 'IMPORT', 5, 2, 711594, 'PN-260417-0006', '2026-04-05 06:28:23.029645');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (52, 'IMPORT', 2, 4, 711594, 'PN-260421-0001', '2026-04-05 21:55:55.11398');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (53, 'IMPORT', 11, 2, 333011, 'PN-260511-0009', '2026-04-09 14:10:57.54907');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (54, 'IMPORT', 11, 2, 333011, 'PN-260426-0005', '2026-04-21 18:31:05.558344');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (55, 'IMPORT', 16, 1, 711594, 'PN-260421-0001', '2026-04-08 18:23:36.938383');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (56, 'EXPORT', 24, 3, 711594, 'PX-260514-6478', '2026-05-14 13:57:07.979437');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (57, 'IMPORT', 17, 4, 711594, 'PN-260406-0007', '2026-05-09 03:02:32.119686');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (58, 'IMPORT', 2, 3, 711594, 'PN-260511-0009', '2026-05-03 03:23:19.435763');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (59, 'IMPORT', 1, 4, 711594, 'PN-260515-0002', '2026-05-27 03:34:16.234552');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (60, 'IMPORT', 15, 2, 711594, 'PN-260421-0001', '2026-04-29 20:42:43.926326');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (61, 'EXPORT', 15, 4, 711594, 'PX-260423-8256', '2026-04-23 10:39:46.059435');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (62, 'IMPORT', 28, 5, 333011, 'PN-260528-0001', '2026-05-28 01:44:55.234731');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (63, 'EXPORT', 17, 4, 333011, 'PX-260528-0001', '2026-05-28 02:17:36.992691');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (64, 'IMPORT', 5, 10, 333011, 'PN-260529-0001', '2026-05-29 01:48:44.41457');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (65, 'EXPORT', 10, 5, 333011, 'PX-260529-0001', '2026-05-29 03:08:31.290236');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (66, 'EXPORT', 28, 3, 333011, 'PX-260529-0002', '2026-05-29 03:24:07.961305');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (67, 'IMPORT', 16, 3, 333011, 'PN-260529-0002', '2026-05-29 03:43:00.637225');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (68, 'IMPORT', 13, 1, 333011, 'PN-260529-0002', '2026-05-29 03:43:00.637225');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (69, 'IMPORT', 3, 4, 333011, 'PN-260529-0002', '2026-05-29 03:43:00.637225');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (70, 'EXPORT', 5, 9, 333011, 'PX-260529-0003', '2026-05-29 03:43:55.5199');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (71, 'IMPORT', 21, 5, 333011, 'PN-260530-0001', '2026-06-02 04:17:05.719301');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (72, 'EXPORT', 5, 2, 711594, 'PX-260602-0001', '2026-06-02 04:22:47.220947');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (73, 'EXPORT', 9, 2, 333011, 'PX-260602-0002', '2026-06-02 04:26:07.573952');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (74, 'IMPORT', 12, 10, 333011, 'PN-260602-0001', '2026-06-02 04:33:27.587086');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (75, 'IMPORT', 20, 10, 333011, 'PN-260602-0001', '2026-06-02 04:33:27.587086');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (76, 'IMPORT', 3, 10, 333011, 'PN-260602-0001', '2026-06-02 04:33:27.587086');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (77, 'EXPORT', 14, 5, 333011, 'PX-260602-0003', '2026-06-02 04:35:38.304946');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (78, 'IMPORT', 14, 20, 711594, 'PN-260602-0002', '2026-06-02 04:38:04.624524');
INSERT INTO public.transactions (transaction_id, transaction_type, variant_id, quantity, user_id, reference_id, created_at) VALUES (79, 'IMPORT', 7, 9, 333011, 'PN-260602-0004', '2026-06-02 04:51:22.170479');


--
-- TOC entry 5320 (class 0 OID 51271)
-- Dependencies: 266
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (138222, '$2y$10$VdzcwyYojuQCtGNWBD1HzOFLdBHknI34vFzXTtPe9yEheiZXytK/a', 'Phan Quốc Kiệt', 'MANAGER', true, '2026-03-31 13:12:46.232382', false, '0901234500', '23 Lê Lợi, Phường Bến Thành, Quận 1, TP.HCM');
INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (506714, '$2y$10$LSkU2aKw6vyCyoeHyA15Pe.kP/JFhR32K8Xa4838MKapz/CMtLNwG', 'Phan Kiet', 'STAFF', true, '2026-03-31 13:12:46.232382', false, '0933445566', '10 Hoàng Diệu, Quận Ba Đình, Hà Nội');
INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (790487, '$2y$10$oBqkXk35nx9pEHZS.xWxz.kW90YjgXO5pv/BBJBMS5D3cDxOWqvSS', 'Quoc Kiet', 'STAFF', true, '2026-03-31 13:12:46.232382', true, '0944556677', '11 Bạch Đằng, Quận Hồng Bàng, Hải Phòng');
INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (971647, '$2y$10$gxMB28Affm1TMJ.kUIKZ/uQEbj2ap2ldl6UtU6zDsLWPLifgV4oJ6', 'kiet123', 'STAFF', true, '2026-03-31 13:12:46.232382', false, '0955667788', '12 Quang Trung, TP. Đà Lạt, Lâm Đồng');
INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (969873, '$2y$10$UWHS9vPoVGqTE3jbdIZZpOrPoCZVu6kVZWaKN.CY9.IWAVigdG05e', 'Nguyễn Văn An', 'MANAGER', true, '2026-04-26 23:35:41.762905', false, NULL, NULL);
INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (118999, '$2y$10$Fc3.PnmFidTPOJMbbgPDUeXX40mn6qiOxtL9xczs7O8rCaOCox/SK', 'Nguyễn Anh Tuấn', 'MANAGER', true, '2026-04-26 23:43:26.305638', false, '0901234512', '245 Dương Quảng Hàm, Gò Vấp, TP.CHM');
INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (698489, '$2y$10$VdzcwyYojuQCtGNWBD1HzOFLdBHknI34vFzXTtPe9yEheiZXytK/a', 'Mai Văn Vũ', 'ADMIN', true, '2026-05-22 23:57:40.470946', false, '0123456980', '23 Lê Lợi, Phường Bến Thành, Quận 1, TP.HCM');
INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (127837, '$2y$10$elCYTBuKYuG5HNnbFhWH8.SF9nNwWdTe9gwsdZUFnoYE7kqypPRpW', 'kietdz', 'STAFF', false, '2026-03-31 13:12:46.232382', false, '0966778899', '13 Nguyễn Trãi, Quận 5, TP.HCM');
INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (992001, '$2y$10$bxoICqHTu6MoKTT8gDMkN.sWi5NUn0lK8d6aRK.OIjBXuqZB4ig.a', 'kiettest', 'STAFF', true, '2026-04-02 23:09:25.594093', true, NULL, NULL);
INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (581968, '$2y$10$7xWX2U64aA.GWeoJqidGd.iP4QYIQdAZGYICo7txUiY3r7hYVwgky', 'kiettest456', 'STAFF', true, '2026-05-23 01:08:16.103685', false, NULL, NULL);
INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (711594, '$2y$10$f86nxwquQB5QMKrWUfb1pudKTVmUwtZxi2ClDlvxtnbaWNQJw2ene', 'Nguyễn Văn Mạnh', 'STAFF', true, '2026-03-31 13:12:46.232382', false, '0987654321', '789 Trần Hưng Đạo, Quận Sơn Trà, Đà Nẵng');
INSERT INTO public.users (user_id, password_hash, full_name, role, status, created_at, is_deleted, phone_number, address) VALUES (333011, '$2y$10$MIlF4hYtfwwlUYGyvv7.T.Ki2xiXFPPNOiWI.zXMVomiKf9YvoTa6', 'Trần Thị Yến', 'STAFF', true, '2026-03-31 13:12:46.232382', false, '0901234500', '85 Nguyễn Huệ, Quận Ninh Kiều, Cần Thơ');


--
-- TOC entry 5329 (class 0 OID 0)
-- Dependencies: 240
-- Name: ai_forecasts_forecast_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.ai_forecasts_forecast_id_seq', 1, false);


--
-- TOC entry 5330 (class 0 OID 0)
-- Dependencies: 241
-- Name: categories_category_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.categories_category_id_seq', 17, true);


--
-- TOC entry 5331 (class 0 OID 0)
-- Dependencies: 243
-- Name: inventory_inventory_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.inventory_inventory_id_seq', 1, false);


--
-- TOC entry 5332 (class 0 OID 0)
-- Dependencies: 244
-- Name: pending_imports_approval_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.pending_imports_approval_id_seq', 2, true);


--
-- TOC entry 5333 (class 0 OID 0)
-- Dependencies: 246
-- Name: product_variants_variant_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.product_variants_variant_id_seq', 42, true);


--
-- TOC entry 5334 (class 0 OID 0)
-- Dependencies: 247
-- Name: product_variants_variant_id_seq1; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.product_variants_variant_id_seq1', 28, true);


--
-- TOC entry 5335 (class 0 OID 0)
-- Dependencies: 249
-- Name: products_product_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.products_product_id_seq', 39, true);


--
-- TOC entry 5336 (class 0 OID 0)
-- Dependencies: 250
-- Name: products_product_id_seq1; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.products_product_id_seq1', 11, true);


--
-- TOC entry 5337 (class 0 OID 0)
-- Dependencies: 252
-- Name: shelves_shelf_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.shelves_shelf_id_seq', 9, true);


--
-- TOC entry 5338 (class 0 OID 0)
-- Dependencies: 253
-- Name: shelves_shelf_id_seq1; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.shelves_shelf_id_seq1', 1, false);


--
-- TOC entry 5339 (class 0 OID 0)
-- Dependencies: 255
-- Name: system_audit_logs_audit_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.system_audit_logs_audit_id_seq', 87, true);


--
-- TOC entry 5340 (class 0 OID 0)
-- Dependencies: 256
-- Name: system_logs_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.system_logs_log_id_seq', 3, true);


--
-- TOC entry 5341 (class 0 OID 0)
-- Dependencies: 258
-- Name: ticket_details_detail_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.ticket_details_detail_id_seq', 47, true);


--
-- TOC entry 5342 (class 0 OID 0)
-- Dependencies: 260
-- Name: ticket_import_temp_temp_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.ticket_import_temp_temp_id_seq', 11, true);


--
-- TOC entry 5343 (class 0 OID 0)
-- Dependencies: 262
-- Name: tickets_ticket_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tickets_ticket_id_seq', 28, true);


--
-- TOC entry 5344 (class 0 OID 0)
-- Dependencies: 264
-- Name: transactions_transaction_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.transactions_transaction_id_seq', 617, true);


--
-- TOC entry 5345 (class 0 OID 0)
-- Dependencies: 265
-- Name: transactions_transaction_id_seq1; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.transactions_transaction_id_seq1', 79, true);


--
-- TOC entry 5346 (class 0 OID 0)
-- Dependencies: 267
-- Name: users_user_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.users_user_id_seq', 51, true);


--
-- TOC entry 5103 (class 2606 OID 51292)
-- Name: categories categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_pkey PRIMARY KEY (category_id);


--
-- TOC entry 5106 (class 2606 OID 51294)
-- Name: product_variants product_variants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_variants
    ADD CONSTRAINT product_variants_pkey PRIMARY KEY (variant_id);


--
-- TOC entry 5108 (class 2606 OID 51296)
-- Name: product_variants product_variants_sku_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_variants
    ADD CONSTRAINT product_variants_sku_key UNIQUE (sku);


--
-- TOC entry 5110 (class 2606 OID 51298)
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (product_id);


--
-- TOC entry 5112 (class 2606 OID 51300)
-- Name: shelves shelves_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shelves
    ADD CONSTRAINT shelves_pkey PRIMARY KEY (shelf_id);


--
-- TOC entry 5114 (class 2606 OID 51302)
-- Name: shelves shelves_shelf_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shelves
    ADD CONSTRAINT shelves_shelf_name_key UNIQUE (shelf_name);


--
-- TOC entry 5116 (class 2606 OID 51304)
-- Name: system_audit_logs system_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_audit_logs
    ADD CONSTRAINT system_audit_logs_pkey PRIMARY KEY (audit_id);


--
-- TOC entry 5118 (class 2606 OID 51306)
-- Name: ticket_details ticket_details_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_details
    ADD CONSTRAINT ticket_details_pkey PRIMARY KEY (detail_id);


--
-- TOC entry 5120 (class 2606 OID 51308)
-- Name: ticket_import_temp ticket_import_temp_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_import_temp
    ADD CONSTRAINT ticket_import_temp_pkey PRIMARY KEY (temp_id);


--
-- TOC entry 5122 (class 2606 OID 51310)
-- Name: tickets tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_pkey PRIMARY KEY (ticket_id);


--
-- TOC entry 5124 (class 2606 OID 51312)
-- Name: tickets tickets_ticket_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_ticket_code_key UNIQUE (ticket_code);


--
-- TOC entry 5126 (class 2606 OID 51314)
-- Name: transactions transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_pkey PRIMARY KEY (transaction_id);


--
-- TOC entry 5128 (class 2606 OID 51316)
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (user_id);


--
-- TOC entry 5104 (class 1259 OID 51317)
-- Name: unique_category_name_active; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX unique_category_name_active ON public.categories USING btree (category_name) WHERE (is_deleted = false);


--
-- TOC entry 5142 (class 2620 OID 51318)
-- Name: categories trg_audit_categories; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_audit_categories AFTER INSERT OR DELETE OR UPDATE ON public.categories FOR EACH ROW EXECUTE FUNCTION public.fn_log_system_changes();


--
-- TOC entry 5143 (class 2620 OID 51319)
-- Name: product_variants trg_audit_product_variants; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_audit_product_variants AFTER INSERT OR DELETE OR UPDATE ON public.product_variants FOR EACH ROW EXECUTE FUNCTION public.fn_log_system_changes();


--
-- TOC entry 5145 (class 2620 OID 51320)
-- Name: products trg_audit_products; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_audit_products AFTER INSERT OR DELETE OR UPDATE ON public.products FOR EACH ROW EXECUTE FUNCTION public.fn_log_system_changes();


--
-- TOC entry 5146 (class 2620 OID 51321)
-- Name: ticket_details trg_audit_ticket_details; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_audit_ticket_details AFTER INSERT OR DELETE OR UPDATE ON public.ticket_details FOR EACH ROW EXECUTE FUNCTION public.fn_log_system_changes();


--
-- TOC entry 5147 (class 2620 OID 51322)
-- Name: tickets trg_audit_tickets; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_audit_tickets AFTER INSERT OR DELETE OR UPDATE ON public.tickets FOR EACH ROW EXECUTE FUNCTION public.fn_log_system_changes();


--
-- TOC entry 5148 (class 2620 OID 51323)
-- Name: users trg_audit_users; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_audit_users AFTER INSERT OR DELETE OR UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.fn_log_system_changes();


--
-- TOC entry 5144 (class 2620 OID 51324)
-- Name: product_variants trg_clean_shelf_layout_on_delete; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_clean_shelf_layout_on_delete AFTER DELETE OR UPDATE ON public.product_variants FOR EACH ROW EXECUTE FUNCTION public.fn_clean_variant_from_shelves();


--
-- TOC entry 5129 (class 2606 OID 51325)
-- Name: categories fk_categories_created_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT fk_categories_created_by FOREIGN KEY (created_by) REFERENCES public.users(user_id);


--
-- TOC entry 5135 (class 2606 OID 51330)
-- Name: ticket_import_temp fk_temp_staff; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_import_temp
    ADD CONSTRAINT fk_temp_staff FOREIGN KEY (staff_id) REFERENCES public.users(user_id);


--
-- TOC entry 5136 (class 2606 OID 51335)
-- Name: ticket_import_temp fk_temp_ticket; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_import_temp
    ADD CONSTRAINT fk_temp_ticket FOREIGN KEY (ticket_id) REFERENCES public.tickets(ticket_id) ON DELETE CASCADE;


--
-- TOC entry 5137 (class 2606 OID 51340)
-- Name: ticket_import_temp fk_temp_variant; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_import_temp
    ADD CONSTRAINT fk_temp_variant FOREIGN KEY (variant_id) REFERENCES public.product_variants(variant_id);


--
-- TOC entry 5130 (class 2606 OID 51345)
-- Name: product_variants product_variants_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_variants
    ADD CONSTRAINT product_variants_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(product_id) ON DELETE CASCADE;


--
-- TOC entry 5131 (class 2606 OID 51350)
-- Name: products products_category_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.categories(category_id);


--
-- TOC entry 5132 (class 2606 OID 51355)
-- Name: products products_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(user_id);


--
-- TOC entry 5133 (class 2606 OID 51360)
-- Name: ticket_details ticket_details_ticket_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_details
    ADD CONSTRAINT ticket_details_ticket_id_fkey FOREIGN KEY (ticket_id) REFERENCES public.tickets(ticket_id) ON DELETE CASCADE;


--
-- TOC entry 5134 (class 2606 OID 51365)
-- Name: ticket_details ticket_details_variant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_details
    ADD CONSTRAINT ticket_details_variant_id_fkey FOREIGN KEY (variant_id) REFERENCES public.product_variants(variant_id);


--
-- TOC entry 5138 (class 2606 OID 51370)
-- Name: tickets tickets_manager_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_manager_id_fkey FOREIGN KEY (manager_id) REFERENCES public.users(user_id);


--
-- TOC entry 5139 (class 2606 OID 51375)
-- Name: tickets tickets_staff_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_staff_id_fkey FOREIGN KEY (staff_id) REFERENCES public.users(user_id);


--
-- TOC entry 5140 (class 2606 OID 51380)
-- Name: transactions transactions_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- TOC entry 5141 (class 2606 OID 51385)
-- Name: transactions transactions_variant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_variant_id_fkey FOREIGN KEY (variant_id) REFERENCES public.product_variants(variant_id) ON DELETE CASCADE;


-- Completed on 2026-06-02 05:06:14

--
-- PostgreSQL database dump complete
--

\unrestrict jz12BYZupdCmSPUjRCAsMlDQBTcaFjdSNbfv3mSppnEvDgCCNlJSXJ4CL5AX3TG

