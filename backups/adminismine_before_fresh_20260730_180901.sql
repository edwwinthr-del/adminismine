--
-- PostgreSQL database dump
--

\restrict MdW49gSylXzYvexNUTjCryiOT3lt1w2ZcMWdrHgBlOv1CQFRtQx3TS24Vzy7j8N

-- Dumped from database version 16.14
-- Dumped by pg_dump version 16.14

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: activity_log; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.activity_log (
    id bigint NOT NULL,
    log_name character varying(255),
    description text NOT NULL,
    subject_type character varying(255),
    subject_id bigint,
    event character varying(255),
    causer_type character varying(255),
    causer_id bigint,
    attribute_changes json,
    properties json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.activity_log OWNER TO adminismine;

--
-- Name: activity_log_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.activity_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.activity_log_id_seq OWNER TO adminismine;

--
-- Name: activity_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.activity_log_id_seq OWNED BY public.activity_log.id;


--
-- Name: ai_suggestions; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.ai_suggestions (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    kind character varying(255) NOT NULL,
    target character varying(255) NOT NULL,
    proposed json NOT NULL,
    validated json,
    errors json,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    prompt text,
    record_type character varying(255),
    record_id bigint,
    confirmed_at timestamp(0) without time zone,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.ai_suggestions OWNER TO adminismine;

--
-- Name: ai_suggestions_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.ai_suggestions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ai_suggestions_id_seq OWNER TO adminismine;

--
-- Name: ai_suggestions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.ai_suggestions_id_seq OWNED BY public.ai_suggestions.id;


--
-- Name: assistant_messages; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.assistant_messages (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    role character varying(255) NOT NULL,
    content text NOT NULL,
    intent character varying(255),
    data json,
    ai_suggestion_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.assistant_messages OWNER TO adminismine;

--
-- Name: assistant_messages_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.assistant_messages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.assistant_messages_id_seq OWNER TO adminismine;

--
-- Name: assistant_messages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.assistant_messages_id_seq OWNED BY public.assistant_messages.id;


--
-- Name: attendance_records; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.attendance_records (
    id bigint NOT NULL,
    date date NOT NULL,
    employee_id bigint NOT NULL,
    worksite_id bigint NOT NULL,
    master_id bigint,
    status character varying(255) DEFAULT 'present'::character varying NOT NULL,
    regular_hours numeric(5,2),
    overtime_hours numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    overtime_reason character varying(255),
    note character varying(255),
    approval_status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    submitted_at timestamp(0) without time zone,
    submitted_by bigint,
    approved_at timestamp(0) without time zone,
    approved_by bigint,
    rejection_reason character varying(255),
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    daily_rate numeric(18,2),
    working_days_basis smallint,
    regular_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    overtime_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    adjustment_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    adjustment_reason character varying(255),
    total_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    approved_for_payroll boolean DEFAULT false NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.attendance_records OWNER TO adminismine;

--
-- Name: attendance_records_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.attendance_records_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.attendance_records_id_seq OWNER TO adminismine;

--
-- Name: attendance_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.attendance_records_id_seq OWNED BY public.attendance_records.id;


--
-- Name: bank_transactions; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.bank_transactions (
    id bigint NOT NULL,
    date date NOT NULL,
    description_1 character varying(255),
    description_2 character varying(255),
    cash_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    nlb_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    lovcen_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    category character varying(255),
    supplier_id bigint,
    client_id bigint,
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    import_source character varying(255),
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.bank_transactions OWNER TO adminismine;

--
-- Name: bank_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.bank_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bank_transactions_id_seq OWNER TO adminismine;

--
-- Name: bank_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.bank_transactions_id_seq OWNED BY public.bank_transactions.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


ALTER TABLE public.cache OWNER TO adminismine;

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


ALTER TABLE public.cache_locks OWNER TO adminismine;

--
-- Name: clients; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.clients (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    tax_number character varying(255),
    contact_name character varying(255),
    phone character varying(255),
    email character varying(255),
    address character varying(255),
    iban character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.clients OWNER TO adminismine;

--
-- Name: clients_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.clients_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.clients_id_seq OWNER TO adminismine;

--
-- Name: clients_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.clients_id_seq OWNED BY public.clients.id;


--
-- Name: company_settings; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.company_settings (
    id bigint NOT NULL,
    company_name character varying(255) DEFAULT 'Global Mine DOO'::character varying NOT NULL,
    base_currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    default_locale character varying(5) DEFAULT 'en'::character varying NOT NULL,
    timezone character varying(255) DEFAULT 'Europe/Podgorica'::character varying NOT NULL,
    tax_number character varying(255),
    address character varying(255),
    phone character varying(255),
    email character varying(255),
    logo_path character varying(255),
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.company_settings OWNER TO adminismine;

--
-- Name: company_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.company_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.company_settings_id_seq OWNER TO adminismine;

--
-- Name: company_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.company_settings_id_seq OWNED BY public.company_settings.id;


--
-- Name: customs_documents; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.customs_documents (
    id bigint NOT NULL,
    document_type character varying(255) DEFAULT 'cmr'::character varying NOT NULL,
    document_number character varying(255),
    cmr_number character varying(255),
    issue_date date,
    cmr_date date,
    shipment_date date,
    customs_company_id bigint,
    customs_company_name character varying(255),
    customs_invoice_number character varying(255),
    sender character varying(255),
    receiver character varying(255),
    carrier_name character varying(255),
    vehicle_plate character varying(255),
    driver_name character varying(255),
    goods_description character varying(255),
    quantity numeric(18,3),
    unit character varying(255),
    origin_place character varying(255),
    destination_place character varying(255),
    machine_id bigint,
    payable_invoice_id bigint,
    receivable_invoice_id bigint,
    client_id bigint,
    supplier_id bigint,
    production_record_id bigint,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.customs_documents OWNER TO adminismine;

--
-- Name: customs_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.customs_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.customs_documents_id_seq OWNER TO adminismine;

--
-- Name: customs_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.customs_documents_id_seq OWNED BY public.customs_documents.id;


--
-- Name: employee_worksite; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.employee_worksite (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    worksite_id bigint NOT NULL,
    assigned_from date,
    assigned_to date,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.employee_worksite OWNER TO adminismine;

--
-- Name: employee_worksite_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.employee_worksite_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employee_worksite_id_seq OWNER TO adminismine;

--
-- Name: employee_worksite_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.employee_worksite_id_seq OWNED BY public.employee_worksite.id;


--
-- Name: employees; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.employees (
    id bigint NOT NULL,
    first_name character varying(255) NOT NULL,
    last_name character varying(255) NOT NULL,
    origin_country character varying(255),
    passport_number character varying(255),
    id_number character varying(255),
    job_role character varying(255),
    bank_account_number character varying(255),
    bank_name character varying(255),
    bank_account_status character varying(255) DEFAULT 'unknown'::character varying NOT NULL,
    base_salary numeric(18,2),
    salary_currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    salary_period character varying(255) DEFAULT 'monthly'::character varying NOT NULL,
    salary_calculation_rule character varying(255) DEFAULT 'working_days'::character varying NOT NULL,
    daily_rate_override numeric(18,2),
    overtime_multiplier numeric(5,2),
    overtime_hourly_rate numeric(18,2),
    contract_start_date date,
    contract_end_date date,
    work_permit_expiry date,
    residence_permit_expiry date,
    medical_exam_expiry date,
    safety_training_expiry date,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.employees OWNER TO adminismine;

--
-- Name: employees_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.employees_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employees_id_seq OWNER TO adminismine;

--
-- Name: employees_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.employees_id_seq OWNED BY public.employees.id;


--
-- Name: exchange_rates; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.exchange_rates (
    id bigint NOT NULL,
    base_currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    quote_currency character varying(3) NOT NULL,
    rate numeric(20,10) NOT NULL,
    rate_date date NOT NULL,
    provider character varying(255),
    is_manual boolean DEFAULT false NOT NULL,
    override_reason text,
    fetched_at timestamp(0) without time zone,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.exchange_rates OWNER TO adminismine;

--
-- Name: exchange_rates_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.exchange_rates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.exchange_rates_id_seq OWNER TO adminismine;

--
-- Name: exchange_rates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.exchange_rates_id_seq OWNED BY public.exchange_rates.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection character varying(255) NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO adminismine;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO adminismine;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: file_attachments; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.file_attachments (
    id bigint NOT NULL,
    attachable_type character varying(255) NOT NULL,
    attachable_id bigint NOT NULL,
    kind character varying(255) DEFAULT 'other'::character varying NOT NULL,
    label character varying(255),
    file_path character varying(255) NOT NULL,
    original_name character varying(255) NOT NULL,
    mime_type character varying(255),
    size_bytes bigint,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.file_attachments OWNER TO adminismine;

--
-- Name: file_attachments_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.file_attachments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.file_attachments_id_seq OWNER TO adminismine;

--
-- Name: file_attachments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.file_attachments_id_seq OWNED BY public.file_attachments.id;


--
-- Name: flight_tickets; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.flight_tickets (
    id bigint NOT NULL,
    employee_id bigint,
    passenger_name character varying(255),
    ticket_date date NOT NULL,
    direction character varying(255) NOT NULL,
    route character varying(255),
    airline character varying(255),
    reference character varying(255),
    currency character varying(3) DEFAULT 'TRY'::character varying NOT NULL,
    amount numeric(18,2) NOT NULL,
    exchange_rate numeric(18,10),
    exchange_rate_date date,
    amount_eur numeric(18,2) NOT NULL,
    paid_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    remaining_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(255) DEFAULT 'unpaid'::character varying NOT NULL,
    cost_status character varying(255) DEFAULT 'not_written'::character varying NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.flight_tickets OWNER TO adminismine;

--
-- Name: flight_tickets_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.flight_tickets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.flight_tickets_id_seq OWNER TO adminismine;

--
-- Name: flight_tickets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.flight_tickets_id_seq OWNED BY public.flight_tickets.id;


--
-- Name: house_occupancies; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.house_occupancies (
    id bigint NOT NULL,
    house_id bigint NOT NULL,
    employee_id bigint NOT NULL,
    room character varying(255),
    moved_in_at date NOT NULL,
    moved_out_at date,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.house_occupancies OWNER TO adminismine;

--
-- Name: house_occupancies_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.house_occupancies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.house_occupancies_id_seq OWNER TO adminismine;

--
-- Name: house_occupancies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.house_occupancies_id_seq OWNED BY public.house_occupancies.id;


--
-- Name: houses; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.houses (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    address character varying(255),
    landlord_name character varying(255),
    landlord_phone character varying(255),
    landlord_id_number character varying(255),
    landlord_bank_account character varying(255),
    monthly_rent numeric(18,2),
    deposit numeric(18,2),
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    contract_start_date date,
    contract_end_date date,
    rent_due_day smallint,
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.houses OWNER TO adminismine;

--
-- Name: houses_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.houses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.houses_id_seq OWNER TO adminismine;

--
-- Name: houses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.houses_id_seq OWNED BY public.houses.id;


--
-- Name: housing_deductions; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.housing_deductions (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    house_id bigint NOT NULL,
    month date NOT NULL,
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    rent_share numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    utility_share numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    amount_deducted numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    remaining_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    reason character varying(255) NOT NULL,
    utility_bill_id bigint,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.housing_deductions OWNER TO adminismine;

--
-- Name: housing_deductions_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.housing_deductions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.housing_deductions_id_seq OWNER TO adminismine;

--
-- Name: housing_deductions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.housing_deductions_id_seq OWNED BY public.housing_deductions.id;


--
-- Name: import_batches; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.import_batches (
    id bigint NOT NULL,
    original_name character varying(255) NOT NULL,
    file_path character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'previewed'::character varying NOT NULL,
    sheet_summary json,
    totals json,
    error text,
    imported_at timestamp(0) without time zone,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    entity character varying(255)
);


ALTER TABLE public.import_batches OWNER TO adminismine;

--
-- Name: import_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.import_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.import_batches_id_seq OWNER TO adminismine;

--
-- Name: import_batches_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.import_batches_id_seq OWNED BY public.import_batches.id;


--
-- Name: import_rows; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.import_rows (
    id bigint NOT NULL,
    import_batch_id bigint NOT NULL,
    sheet_name character varying(255) NOT NULL,
    row_number integer NOT NULL,
    target character varying(255) NOT NULL,
    raw json NOT NULL,
    mapped json,
    issues json,
    action character varying(255) DEFAULT 'create'::character varying NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    record_type character varying(255),
    record_id bigint,
    error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.import_rows OWNER TO adminismine;

--
-- Name: import_rows_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.import_rows_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.import_rows_id_seq OWNER TO adminismine;

--
-- Name: import_rows_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.import_rows_id_seq OWNED BY public.import_rows.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


ALTER TABLE public.job_batches OWNER TO adminismine;

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE public.jobs OWNER TO adminismine;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO adminismine;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: loans; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.loans (
    id bigint NOT NULL,
    counterparty character varying(255) NOT NULL,
    direction character varying(255) DEFAULT 'received'::character varying NOT NULL,
    reference_number character varying(255),
    loan_date date NOT NULL,
    due_date date,
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    original_amount numeric(18,2) NOT NULL,
    exchange_rate numeric(18,10),
    exchange_rate_date date,
    amount_eur numeric(18,2) NOT NULL,
    repaid_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    remaining_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(255) DEFAULT 'outstanding'::character varying NOT NULL,
    supplier_id bigint,
    client_id bigint,
    employee_id bigint,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.loans OWNER TO adminismine;

--
-- Name: loans_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.loans_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.loans_id_seq OWNER TO adminismine;

--
-- Name: loans_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.loans_id_seq OWNED BY public.loans.id;


--
-- Name: machines; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.machines (
    id bigint NOT NULL,
    machine_type character varying(255) NOT NULL,
    brand character varying(255),
    model character varying(255),
    serial_number character varying(255),
    purchase_date date,
    supplier_id bigint,
    seller_name character varying(255),
    purchase_invoice_number character varying(255),
    purchase_amount numeric(18,2),
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    payable_invoice_id bigint,
    bank_transaction_id bigint,
    current_location character varying(255),
    worksite_id bigint,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    registration_expiry date,
    insurance_expiry date
);


ALTER TABLE public.machines OWNER TO adminismine;

--
-- Name: machines_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.machines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.machines_id_seq OWNER TO adminismine;

--
-- Name: machines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.machines_id_seq OWNED BY public.machines.id;


--
-- Name: master_worksite; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.master_worksite (
    id bigint NOT NULL,
    master_id bigint NOT NULL,
    worksite_id bigint NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.master_worksite OWNER TO adminismine;

--
-- Name: master_worksite_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.master_worksite_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.master_worksite_id_seq OWNER TO adminismine;

--
-- Name: master_worksite_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.master_worksite_id_seq OWNED BY public.master_worksite.id;


--
-- Name: masters; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.masters (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    user_id bigint,
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.masters OWNER TO adminismine;

--
-- Name: masters_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.masters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.masters_id_seq OWNER TO adminismine;

--
-- Name: masters_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.masters_id_seq OWNED BY public.masters.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO adminismine;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO adminismine;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: mines; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.mines (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(255),
    location character varying(255),
    material_type character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.mines OWNER TO adminismine;

--
-- Name: mines_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.mines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.mines_id_seq OWNER TO adminismine;

--
-- Name: mines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.mines_id_seq OWNED BY public.mines.id;


--
-- Name: model_has_permissions; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


ALTER TABLE public.model_has_permissions OWNER TO adminismine;

--
-- Name: model_has_roles; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


ALTER TABLE public.model_has_roles OWNER TO adminismine;

--
-- Name: notification_rules; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.notification_rules (
    id bigint NOT NULL,
    type character varying(255) NOT NULL,
    is_enabled boolean DEFAULT true NOT NULL,
    timing character varying(255) DEFAULT 'same_day'::character varying NOT NULL,
    days_before smallint,
    severity character varying(255) DEFAULT 'info'::character varying NOT NULL,
    channels json NOT NULL,
    recipient_roles json NOT NULL,
    recipient_user_ids json NOT NULL,
    config json,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.notification_rules OWNER TO adminismine;

--
-- Name: notification_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.notification_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.notification_rules_id_seq OWNER TO adminismine;

--
-- Name: notification_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.notification_rules_id_seq OWNED BY public.notification_rules.id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.notifications (
    id bigint NOT NULL,
    notification_rule_id bigint,
    user_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    severity character varying(255) DEFAULT 'info'::character varying NOT NULL,
    subject_type character varying(255),
    subject_id bigint,
    data json,
    due_date date,
    period date,
    status character varying(255) DEFAULT 'unread'::character varying NOT NULL,
    read_at timestamp(0) without time zone,
    dismissed_at timestamp(0) without time zone,
    resolved_at timestamp(0) without time zone,
    dedupe_key character varying(255) NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.notifications OWNER TO adminismine;

--
-- Name: notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.notifications_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.notifications_id_seq OWNER TO adminismine;

--
-- Name: notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.notifications_id_seq OWNED BY public.notifications.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO adminismine;

--
-- Name: payable_invoices; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.payable_invoices (
    id bigint NOT NULL,
    supplier_id bigint NOT NULL,
    invoice_number character varying(255),
    invoice_date date NOT NULL,
    due_date date,
    description character varying(255),
    expense_category character varying(255),
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    original_amount numeric(18,2) NOT NULL,
    paid_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    remaining_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(255) DEFAULT 'unpaid'::character varying NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.payable_invoices OWNER TO adminismine;

--
-- Name: payable_invoices_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.payable_invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.payable_invoices_id_seq OWNER TO adminismine;

--
-- Name: payable_invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.payable_invoices_id_seq OWNED BY public.payable_invoices.id;


--
-- Name: payments; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.payments (
    id bigint NOT NULL,
    payable_type character varying(255) NOT NULL,
    payable_id bigint NOT NULL,
    amount numeric(18,2) NOT NULL,
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    payment_date date NOT NULL,
    method character varying(255) NOT NULL,
    bank_transaction_id bigint,
    reference character varying(255),
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.payments OWNER TO adminismine;

--
-- Name: payments_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.payments_id_seq OWNER TO adminismine;

--
-- Name: payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.payments_id_seq OWNED BY public.payments.id;


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.permissions OWNER TO adminismine;

--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.permissions_id_seq OWNER TO adminismine;

--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.personal_access_tokens OWNER TO adminismine;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.personal_access_tokens_id_seq OWNER TO adminismine;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: production_records; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.production_records (
    id bigint NOT NULL,
    period_type character varying(255) DEFAULT 'daily'::character varying NOT NULL,
    date date,
    period_month date NOT NULL,
    worksite_id bigint NOT NULL,
    engineer_id bigint,
    material_type character varying(255) DEFAULT 'bauxite_ore'::character varying NOT NULL,
    quantity numeric(18,3) NOT NULL,
    unit character varying(255) DEFAULT 'tons'::character varying NOT NULL,
    quality_grade character varying(255),
    attachment_path character varying(255),
    approval_status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    approved_at timestamp(0) without time zone,
    approved_by bigint,
    rejection_reason character varying(255),
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.production_records OWNER TO adminismine;

--
-- Name: production_records_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.production_records_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.production_records_id_seq OWNER TO adminismine;

--
-- Name: production_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.production_records_id_seq OWNED BY public.production_records.id;


--
-- Name: projects; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.projects (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(255),
    client_id bigint,
    start_date date,
    end_date date,
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.projects OWNER TO adminismine;

--
-- Name: projects_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.projects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.projects_id_seq OWNER TO adminismine;

--
-- Name: projects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.projects_id_seq OWNED BY public.projects.id;


--
-- Name: receivable_deductions; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.receivable_deductions (
    id bigint NOT NULL,
    receivable_invoice_id bigint NOT NULL,
    amount numeric(18,2) NOT NULL,
    deduction_date date NOT NULL,
    reason character varying(255),
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.receivable_deductions OWNER TO adminismine;

--
-- Name: receivable_deductions_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.receivable_deductions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.receivable_deductions_id_seq OWNER TO adminismine;

--
-- Name: receivable_deductions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.receivable_deductions_id_seq OWNED BY public.receivable_deductions.id;


--
-- Name: receivable_invoices; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.receivable_invoices (
    id bigint NOT NULL,
    client_id bigint NOT NULL,
    invoice_number character varying(255),
    invoice_date date NOT NULL,
    due_date date,
    description character varying(255),
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    invoice_amount numeric(18,2) NOT NULL,
    received_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    deducted_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    remaining_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(255) DEFAULT 'unpaid'::character varying NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.receivable_invoices OWNER TO adminismine;

--
-- Name: receivable_invoices_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.receivable_invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.receivable_invoices_id_seq OWNER TO adminismine;

--
-- Name: receivable_invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.receivable_invoices_id_seq OWNED BY public.receivable_invoices.id;


--
-- Name: rent_payments; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.rent_payments (
    id bigint NOT NULL,
    house_id bigint NOT NULL,
    month date NOT NULL,
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    rent_amount_due numeric(18,2) NOT NULL,
    paid_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    remaining_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(255) DEFAULT 'unpaid'::character varying NOT NULL,
    cost_bearer character varying(255) DEFAULT 'company'::character varying NOT NULL,
    exception_reason character varying(255),
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.rent_payments OWNER TO adminismine;

--
-- Name: rent_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.rent_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.rent_payments_id_seq OWNER TO adminismine;

--
-- Name: rent_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.rent_payments_id_seq OWNED BY public.rent_payments.id;


--
-- Name: role_has_permissions; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


ALTER TABLE public.role_has_permissions OWNER TO adminismine;

--
-- Name: roles; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_system boolean DEFAULT false NOT NULL
);


ALTER TABLE public.roles OWNER TO adminismine;

--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.roles_id_seq OWNER TO adminismine;

--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: salary_payments; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.salary_payments (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    salary_month date NOT NULL,
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    base_salary numeric(18,2) NOT NULL,
    adjustments numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    deductions numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    net_salary_due numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    paid_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    remaining_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(255) DEFAULT 'unpaid'::character varying NOT NULL,
    attachment_path character varying(255),
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.salary_payments OWNER TO adminismine;

--
-- Name: salary_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.salary_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.salary_payments_id_seq OWNER TO adminismine;

--
-- Name: salary_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.salary_payments_id_seq OWNED BY public.salary_payments.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE public.sessions OWNER TO adminismine;

--
-- Name: social_assistance_payments; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.social_assistance_payments (
    id bigint NOT NULL,
    employee_id bigint,
    person_name character varying(255),
    payment_date date NOT NULL,
    entitlement_year smallint NOT NULL,
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    amount numeric(18,2) NOT NULL,
    exchange_rate numeric(18,10),
    exchange_rate_date date,
    amount_eur numeric(18,2) NOT NULL,
    method character varying(255),
    bank_transaction_id bigint,
    reason character varying(255),
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.social_assistance_payments OWNER TO adminismine;

--
-- Name: social_assistance_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.social_assistance_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.social_assistance_payments_id_seq OWNER TO adminismine;

--
-- Name: social_assistance_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.social_assistance_payments_id_seq OWNED BY public.social_assistance_payments.id;


--
-- Name: suppliers; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.suppliers (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    tax_number character varying(255),
    contact_name character varying(255),
    phone character varying(255),
    email character varying(255),
    address character varying(255),
    iban character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.suppliers OWNER TO adminismine;

--
-- Name: suppliers_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.suppliers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.suppliers_id_seq OWNER TO adminismine;

--
-- Name: suppliers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.suppliers_id_seq OWNED BY public.suppliers.id;


--
-- Name: travel_expenses; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.travel_expenses (
    id bigint NOT NULL,
    employee_id bigint,
    person_name character varying(255),
    expense_date date NOT NULL,
    period_month date NOT NULL,
    expense_type character varying(255) NOT NULL,
    flight_ticket_id bigint,
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    amount numeric(18,2) NOT NULL,
    exchange_rate numeric(18,10),
    exchange_rate_date date,
    amount_eur numeric(18,2) NOT NULL,
    paid_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    remaining_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(255) DEFAULT 'unpaid'::character varying NOT NULL,
    cost_status character varying(255) DEFAULT 'not_written'::character varying NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.travel_expenses OWNER TO adminismine;

--
-- Name: travel_expenses_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.travel_expenses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.travel_expenses_id_seq OWNER TO adminismine;

--
-- Name: travel_expenses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.travel_expenses_id_seq OWNED BY public.travel_expenses.id;


--
-- Name: user_notification_preferences; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.user_notification_preferences (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    is_enabled boolean,
    channels json,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.user_notification_preferences OWNER TO adminismine;

--
-- Name: user_notification_preferences_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.user_notification_preferences_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.user_notification_preferences_id_seq OWNER TO adminismine;

--
-- Name: user_notification_preferences_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.user_notification_preferences_id_seq OWNED BY public.user_notification_preferences.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    locale character varying(5) DEFAULT 'en'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL
);


ALTER TABLE public.users OWNER TO adminismine;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO adminismine;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: utility_bills; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.utility_bills (
    id bigint NOT NULL,
    house_id bigint NOT NULL,
    bill_type character varying(255) NOT NULL,
    billing_period date NOT NULL,
    amount numeric(18,2) NOT NULL,
    currency character varying(3) DEFAULT 'EUR'::character varying NOT NULL,
    due_date date,
    paid_date date,
    paid_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    remaining_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(255) DEFAULT 'unpaid'::character varying NOT NULL,
    cost_bearer character varying(255) DEFAULT 'company'::character varying NOT NULL,
    exception_reason character varying(255),
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.utility_bills OWNER TO adminismine;

--
-- Name: utility_bills_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.utility_bills_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.utility_bills_id_seq OWNER TO adminismine;

--
-- Name: utility_bills_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.utility_bills_id_seq OWNED BY public.utility_bills.id;


--
-- Name: worker_needs; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.worker_needs (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    worksite_id bigint,
    date date NOT NULL,
    need_type character varying(255) NOT NULL,
    description text NOT NULL,
    priority character varying(255) DEFAULT 'normal'::character varying NOT NULL,
    status character varying(255) DEFAULT 'open'::character varying NOT NULL,
    assigned_user_id bigint,
    resolved_at timestamp(0) without time zone,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.worker_needs OWNER TO adminismine;

--
-- Name: worker_needs_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.worker_needs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.worker_needs_id_seq OWNER TO adminismine;

--
-- Name: worker_needs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.worker_needs_id_seq OWNED BY public.worker_needs.id;


--
-- Name: working_day_settings; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.working_day_settings (
    id bigint NOT NULL,
    month date NOT NULL,
    working_days smallint NOT NULL,
    reason character varying(255) NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.working_day_settings OWNER TO adminismine;

--
-- Name: working_day_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.working_day_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.working_day_settings_id_seq OWNER TO adminismine;

--
-- Name: working_day_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.working_day_settings_id_seq OWNED BY public.working_day_settings.id;


--
-- Name: worksites; Type: TABLE; Schema: public; Owner: adminismine
--

CREATE TABLE public.worksites (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    location character varying(255),
    client_id bigint,
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint,
    updated_by bigint,
    source character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    mine_id bigint,
    project_id bigint
);


ALTER TABLE public.worksites OWNER TO adminismine;

--
-- Name: worksites_id_seq; Type: SEQUENCE; Schema: public; Owner: adminismine
--

CREATE SEQUENCE public.worksites_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.worksites_id_seq OWNER TO adminismine;

--
-- Name: worksites_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: adminismine
--

ALTER SEQUENCE public.worksites_id_seq OWNED BY public.worksites.id;


--
-- Name: activity_log id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.activity_log ALTER COLUMN id SET DEFAULT nextval('public.activity_log_id_seq'::regclass);


--
-- Name: ai_suggestions id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.ai_suggestions ALTER COLUMN id SET DEFAULT nextval('public.ai_suggestions_id_seq'::regclass);


--
-- Name: assistant_messages id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.assistant_messages ALTER COLUMN id SET DEFAULT nextval('public.assistant_messages_id_seq'::regclass);


--
-- Name: attendance_records id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.attendance_records ALTER COLUMN id SET DEFAULT nextval('public.attendance_records_id_seq'::regclass);


--
-- Name: bank_transactions id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.bank_transactions ALTER COLUMN id SET DEFAULT nextval('public.bank_transactions_id_seq'::regclass);


--
-- Name: clients id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.clients ALTER COLUMN id SET DEFAULT nextval('public.clients_id_seq'::regclass);


--
-- Name: company_settings id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.company_settings ALTER COLUMN id SET DEFAULT nextval('public.company_settings_id_seq'::regclass);


--
-- Name: customs_documents id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.customs_documents ALTER COLUMN id SET DEFAULT nextval('public.customs_documents_id_seq'::regclass);


--
-- Name: employee_worksite id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.employee_worksite ALTER COLUMN id SET DEFAULT nextval('public.employee_worksite_id_seq'::regclass);


--
-- Name: employees id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.employees ALTER COLUMN id SET DEFAULT nextval('public.employees_id_seq'::regclass);


--
-- Name: exchange_rates id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.exchange_rates ALTER COLUMN id SET DEFAULT nextval('public.exchange_rates_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: file_attachments id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.file_attachments ALTER COLUMN id SET DEFAULT nextval('public.file_attachments_id_seq'::regclass);


--
-- Name: flight_tickets id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.flight_tickets ALTER COLUMN id SET DEFAULT nextval('public.flight_tickets_id_seq'::regclass);


--
-- Name: house_occupancies id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.house_occupancies ALTER COLUMN id SET DEFAULT nextval('public.house_occupancies_id_seq'::regclass);


--
-- Name: houses id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.houses ALTER COLUMN id SET DEFAULT nextval('public.houses_id_seq'::regclass);


--
-- Name: housing_deductions id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.housing_deductions ALTER COLUMN id SET DEFAULT nextval('public.housing_deductions_id_seq'::regclass);


--
-- Name: import_batches id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.import_batches ALTER COLUMN id SET DEFAULT nextval('public.import_batches_id_seq'::regclass);


--
-- Name: import_rows id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.import_rows ALTER COLUMN id SET DEFAULT nextval('public.import_rows_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: loans id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.loans ALTER COLUMN id SET DEFAULT nextval('public.loans_id_seq'::regclass);


--
-- Name: machines id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.machines ALTER COLUMN id SET DEFAULT nextval('public.machines_id_seq'::regclass);


--
-- Name: master_worksite id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.master_worksite ALTER COLUMN id SET DEFAULT nextval('public.master_worksite_id_seq'::regclass);


--
-- Name: masters id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.masters ALTER COLUMN id SET DEFAULT nextval('public.masters_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: mines id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.mines ALTER COLUMN id SET DEFAULT nextval('public.mines_id_seq'::regclass);


--
-- Name: notification_rules id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notification_rules ALTER COLUMN id SET DEFAULT nextval('public.notification_rules_id_seq'::regclass);


--
-- Name: notifications id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notifications ALTER COLUMN id SET DEFAULT nextval('public.notifications_id_seq'::regclass);


--
-- Name: payable_invoices id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.payable_invoices ALTER COLUMN id SET DEFAULT nextval('public.payable_invoices_id_seq'::regclass);


--
-- Name: payments id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.payments ALTER COLUMN id SET DEFAULT nextval('public.payments_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: production_records id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.production_records ALTER COLUMN id SET DEFAULT nextval('public.production_records_id_seq'::regclass);


--
-- Name: projects id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.projects ALTER COLUMN id SET DEFAULT nextval('public.projects_id_seq'::regclass);


--
-- Name: receivable_deductions id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.receivable_deductions ALTER COLUMN id SET DEFAULT nextval('public.receivable_deductions_id_seq'::regclass);


--
-- Name: receivable_invoices id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.receivable_invoices ALTER COLUMN id SET DEFAULT nextval('public.receivable_invoices_id_seq'::regclass);


--
-- Name: rent_payments id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.rent_payments ALTER COLUMN id SET DEFAULT nextval('public.rent_payments_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: salary_payments id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.salary_payments ALTER COLUMN id SET DEFAULT nextval('public.salary_payments_id_seq'::regclass);


--
-- Name: social_assistance_payments id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.social_assistance_payments ALTER COLUMN id SET DEFAULT nextval('public.social_assistance_payments_id_seq'::regclass);


--
-- Name: suppliers id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.suppliers ALTER COLUMN id SET DEFAULT nextval('public.suppliers_id_seq'::regclass);


--
-- Name: travel_expenses id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.travel_expenses ALTER COLUMN id SET DEFAULT nextval('public.travel_expenses_id_seq'::regclass);


--
-- Name: user_notification_preferences id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.user_notification_preferences ALTER COLUMN id SET DEFAULT nextval('public.user_notification_preferences_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: utility_bills id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.utility_bills ALTER COLUMN id SET DEFAULT nextval('public.utility_bills_id_seq'::regclass);


--
-- Name: worker_needs id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worker_needs ALTER COLUMN id SET DEFAULT nextval('public.worker_needs_id_seq'::regclass);


--
-- Name: working_day_settings id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.working_day_settings ALTER COLUMN id SET DEFAULT nextval('public.working_day_settings_id_seq'::regclass);


--
-- Name: worksites id; Type: DEFAULT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worksites ALTER COLUMN id SET DEFAULT nextval('public.worksites_id_seq'::regclass);


--
-- Data for Name: activity_log; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.activity_log (id, log_name, description, subject_type, subject_id, event, causer_type, causer_id, attribute_changes, properties, created_at, updated_at) FROM stdin;
1	default	payable.created	App\\Models\\PayableInvoice	1	\N	App\\Models\\User	1	[]	[]	2026-07-24 21:35:31	2026-07-24 21:35:31
2	default	payable.payment_recorded	App\\Models\\PayableInvoice	1	\N	App\\Models\\User	1	[]	{"amount":500,"method":"cash"}	2026-07-24 21:35:31	2026-07-24 21:35:31
3	default	receivable.created	App\\Models\\ReceivableInvoice	1	\N	App\\Models\\User	1	[]	[]	2026-07-24 21:45:19	2026-07-24 21:45:19
4	default	receivable.payment_recorded	App\\Models\\ReceivableInvoice	1	\N	App\\Models\\User	1	[]	{"amount":2000,"method":"nlb"}	2026-07-24 21:45:19	2026-07-24 21:45:19
5	default	receivable.deduction_recorded	App\\Models\\ReceivableInvoice	1	\N	App\\Models\\User	1	[]	{"amount":500,"reason":"material offset"}	2026-07-24 21:45:19	2026-07-24 21:45:19
6	default	bank_transaction.created	App\\Models\\BankTransaction	1	\N	App\\Models\\User	1	[]	[]	2026-07-24 21:54:43	2026-07-24 21:54:43
7	default	bank_transaction.matched	App\\Models\\BankTransaction	1	\N	App\\Models\\User	1	[]	{"target":"payable","invoice_id":1,"amount":700}	2026-07-24 21:54:43	2026-07-24 21:54:43
8	default	company_settings.updated	App\\Models\\CompanySettings	1	\N	App\\Models\\User	1	[]	{"changes":{"company_name":"Test DOO","updated_by":1,"updated_at":"2026-07-27 09:19:32"}}	2026-07-27 09:19:32	2026-07-27 09:19:32
9	default	report.exported	\N	\N	\N	App\\Models\\User	1	[]	{"report":"monthly_cashflow","format":"pdf","rows":12}	2026-07-27 21:49:53	2026-07-27 21:49:53
10	default	company_settings.updated	App\\Models\\CompanySettings	1	\N	App\\Models\\User	1	[]	{"changes":{"default_locale":"sr","updated_at":"2026-07-27 21:50:49"}}	2026-07-27 21:50:49	2026-07-27 21:50:49
11	default	payable.created	App\\Models\\PayableInvoice	2	\N	App\\Models\\User	1	[]	[]	2026-07-27 21:51:55	2026-07-27 21:51:55
12	default	payable.payment_recorded	App\\Models\\PayableInvoice	2	\N	App\\Models\\User	1	[]	{"amount":120,"method":"nlb"}	2026-07-27 21:52:14	2026-07-27 21:52:14
13	default	payable.payment_recorded	App\\Models\\PayableInvoice	2	\N	App\\Models\\User	1	[]	{"amount":401.12,"method":"nlb"}	2026-07-27 21:53:01	2026-07-27 21:53:01
14	default	employee.created	App\\Models\\Employee	1	\N	App\\Models\\User	1	[]	[]	2026-07-27 21:57:25	2026-07-27 21:57:25
15	default	salary_payments.generated	\N	\N	\N	App\\Models\\User	1	[]	{"month":"2026-07-01","created":1}	2026-07-27 21:57:40	2026-07-27 21:57:40
16	default	salary_payment.payment_recorded	App\\Models\\SalaryPayment	1	\N	App\\Models\\User	1	[]	{"amount":2000,"method":"cash"}	2026-07-27 21:57:58	2026-07-27 21:57:58
17	default	worker_need.created	App\\Models\\WorkerNeed	1	\N	App\\Models\\User	1	[]	[]	2026-07-27 21:59:09	2026-07-27 21:59:09
18	default	worker_need.updated	App\\Models\\WorkerNeed	1	\N	App\\Models\\User	1	[]	[]	2026-07-27 21:59:17	2026-07-27 21:59:17
19	default	worker_need.updated	App\\Models\\WorkerNeed	1	\N	App\\Models\\User	1	[]	[]	2026-07-27 21:59:24	2026-07-27 21:59:24
20	default	worksite.created	App\\Models\\Worksite	1	\N	App\\Models\\User	1	[]	[]	2026-07-27 22:00:45	2026-07-27 22:00:45
21	default	worksite.employees_synced	App\\Models\\Worksite	1	\N	App\\Models\\User	1	[]	{"employee_ids":[1]}	2026-07-27 22:01:00	2026-07-27 22:01:00
22	default	house.created	App\\Models\\House	1	\N	App\\Models\\User	1	[]	[]	2026-07-27 22:02:16	2026-07-27 22:02:16
23	default	travel_expense.created	App\\Models\\TravelExpense	1	\N	App\\Models\\User	1	[]	[]	2026-07-27 22:04:12	2026-07-27 22:04:12
24	default	loan.created	App\\Models\\Loan	1	\N	App\\Models\\User	1	[]	[]	2026-07-27 22:05:54	2026-07-27 22:05:54
25	default	report.exported	\N	\N	\N	App\\Models\\User	1	[]	{"report":"employee_salary_payment_report","format":"xlsx","rows":1}	2026-07-27 22:06:21	2026-07-27 22:06:21
26	default	receivable.payment_recorded	App\\Models\\ReceivableInvoice	1	\N	App\\Models\\User	1	[]	{"amount":2500,"method":"nlb"}	2026-07-27 22:09:08	2026-07-27 22:09:08
27	default	house_occupancy.created	App\\Models\\HouseOccupancy	1	\N	App\\Models\\User	1	[]	{"closed_previous_occupancy_id":null}	2026-07-28 13:19:44	2026-07-28 13:19:44
28	default	import.cancelled	App\\Models\\ImportBatch	1	\N	App\\Models\\User	1	[]	[]	2026-07-28 13:23:53	2026-07-28 13:23:53
29	default	payable.created	App\\Models\\PayableInvoice	3	\N	App\\Models\\User	1	[]	[]	2026-07-28 15:43:21	2026-07-28 15:43:21
30	default	payable.updated	App\\Models\\PayableInvoice	1	\N	App\\Models\\User	1	[]	[]	2026-07-28 15:43:22	2026-07-28 15:43:22
31	default	payable.deleted	App\\Models\\PayableInvoice	1	\N	App\\Models\\User	1	[]	[]	2026-07-28 15:43:24	2026-07-28 15:43:24
32	default	assistant.asked	\N	\N	\N	App\\Models\\User	1	[]	{"intent":"error"}	2026-07-29 19:37:55	2026-07-29 19:37:55
33	default	assistant.asked	\N	\N	\N	App\\Models\\User	1	[]	{"intent":"error"}	2026-07-29 19:52:34	2026-07-29 19:52:34
34	default	assistant.asked	\N	\N	\N	App\\Models\\User	1	[]	{"intent":"error"}	2026-07-29 19:59:29	2026-07-29 19:59:29
35	default	exchange_rate.synced	\N	\N	\N	App\\Models\\User	1	[]	{"quotes":["TRY"]}	2026-07-29 20:07:29	2026-07-29 20:07:29
36	default	exchange_rate.synced	\N	\N	\N	App\\Models\\User	1	[]	{"quotes":["TRY"]}	2026-07-29 20:07:40	2026-07-29 20:07:40
\.


--
-- Data for Name: ai_suggestions; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.ai_suggestions (id, user_id, kind, target, proposed, validated, errors, status, prompt, record_type, record_id, confirmed_at, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: assistant_messages; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.assistant_messages (id, user_id, role, content, intent, data, ai_suggestion_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: attendance_records; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.attendance_records (id, date, employee_id, worksite_id, master_id, status, regular_hours, overtime_hours, overtime_reason, note, approval_status, submitted_at, submitted_by, approved_at, approved_by, rejection_reason, currency, daily_rate, working_days_basis, regular_amount, overtime_amount, adjustment_amount, adjustment_reason, total_amount, approved_for_payroll, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: bank_transactions; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.bank_transactions (id, date, description_1, description_2, cash_amount, nlb_amount, lovcen_amount, category, supplier_id, client_id, currency, import_source, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	2026-07-18	Fuel payment F-2026-014	\N	0.00	-700.00	0.00	expense	\N	\N	EUR	\N	1	1	\N	\N	2026-07-24 21:54:42	2026-07-24 21:54:42
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.cache (key, value, expiration) FROM stdin;
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: clients; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.clients (id, name, tax_number, contact_name, phone, email, address, iban, is_active, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	Uniprom AD	\N	\N	\N	\N	\N	\N	t	1	1	\N	\N	2026-07-24 21:45:19	2026-07-24 21:45:19
\.


--
-- Data for Name: company_settings; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.company_settings (id, company_name, base_currency, default_locale, timezone, tax_number, address, phone, email, logo_path, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	Test DOO	EUR	sr	Europe/Podgorica	\N	\N	\N	\N	\N	\N	1	\N	\N	2026-07-24 20:55:32	2026-07-27 21:50:49
\.


--
-- Data for Name: customs_documents; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.customs_documents (id, document_type, document_number, cmr_number, issue_date, cmr_date, shipment_date, customs_company_id, customs_company_name, customs_invoice_number, sender, receiver, carrier_name, vehicle_plate, driver_name, goods_description, quantity, unit, origin_place, destination_place, machine_id, payable_invoice_id, receivable_invoice_id, client_id, supplier_id, production_record_id, status, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: employee_worksite; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.employee_worksite (id, employee_id, worksite_id, assigned_from, assigned_to, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	1	1	\N	\N	\N	\N	\N	\N	2026-07-27 22:01:00	2026-07-27 22:01:00
\.


--
-- Data for Name: employees; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.employees (id, first_name, last_name, origin_country, passport_number, id_number, job_role, bank_account_number, bank_name, bank_account_status, base_salary, salary_currency, salary_period, salary_calculation_rule, daily_rate_override, overtime_multiplier, overtime_hourly_rate, contract_start_date, contract_end_date, work_permit_expiry, residence_permit_expiry, medical_exam_expiry, safety_training_expiry, status, created_by, updated_by, source, notes, deleted_at, created_at, updated_at) FROM stdin;
1	Test	Test	Turska	TR21231	\N	Rudar	520-00122-212	Lovcen	unknown	2000.00	EUR	monthly	working_days	\N	\N	\N	2026-07-27	\N	\N	\N	\N	\N	active	1	1	\N	\N	\N	2026-07-27 21:57:25	2026-07-27 21:57:25
\.


--
-- Data for Name: exchange_rates; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.exchange_rates (id, base_currency, quote_currency, rate, rate_date, provider, is_manual, override_reason, fetched_at, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	EUR	TRY	53.9386000000	2026-07-29		f	\N	2026-07-29 20:07:40	1	1	\N	\N	2026-07-29 20:07:29	2026-07-29 20:07:40
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: file_attachments; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.file_attachments (id, attachable_type, attachable_id, kind, label, file_path, original_name, mime_type, size_bytes, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: flight_tickets; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.flight_tickets (id, employee_id, passenger_name, ticket_date, direction, route, airline, reference, currency, amount, exchange_rate, exchange_rate_date, amount_eur, paid_amount, remaining_amount, status, cost_status, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: house_occupancies; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.house_occupancies (id, house_id, employee_id, room, moved_in_at, moved_out_at, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	1	1	1	2026-07-28	\N	1	1	\N	\N	2026-07-28 13:19:44	2026-07-28 13:19:44
\.


--
-- Data for Name: houses; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.houses (id, name, address, landlord_name, landlord_phone, landlord_id_number, landlord_bank_account, monthly_rent, deposit, currency, contract_start_date, contract_end_date, rent_due_day, is_active, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	Test	NK	Test Testic	\N	\N	\N	500.00	0.00	EUR	2026-07-28	\N	\N	t	1	1	\N	\N	2026-07-27 22:02:15	2026-07-27 22:02:15
\.


--
-- Data for Name: housing_deductions; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.housing_deductions (id, employee_id, house_id, month, currency, rent_share, utility_share, amount_deducted, remaining_amount, reason, utility_bill_id, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: import_batches; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.import_batches (id, original_name, file_path, status, sheet_summary, totals, error, imported_at, created_by, updated_by, source, notes, created_at, updated_at, entity) FROM stdin;
1	GLOBAL MINE DOO KASA-BANKA HAREKETLERİ-ET2.xlsx	imports/Eo3oWzbdshhdi4XuVkDzWZmwVNs968mbwFQkh9h4.xlsx	cancelled	\N	\N	\N	\N	1	1	upload	\N	2026-07-28 12:59:03	2026-07-28 13:23:53	\N
\.


--
-- Data for Name: import_rows; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.import_rows (id, import_batch_id, sheet_name, row_number, target, raw, mapped, issues, action, status, record_type, record_id, error, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: job_batches; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at) FROM stdin;
\.


--
-- Data for Name: jobs; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) FROM stdin;
\.


--
-- Data for Name: loans; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.loans (id, counterparty, direction, reference_number, loan_date, due_date, currency, original_amount, exchange_rate, exchange_rate_date, amount_eur, repaid_amount, remaining_amount, status, supplier_id, client_id, employee_id, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	NORTH-EX	received	101/01	2026-07-27	2026-07-29	EUR	499.99	\N	\N	499.99	0.00	499.99	outstanding	\N	\N	\N	1	1	\N	\N	2026-07-27 22:05:54	2026-07-27 22:05:54
\.


--
-- Data for Name: machines; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.machines (id, machine_type, brand, model, serial_number, purchase_date, supplier_id, seller_name, purchase_invoice_number, purchase_amount, currency, payable_invoice_id, bank_transaction_id, current_location, worksite_id, status, created_by, updated_by, source, notes, created_at, updated_at, registration_expiry, insurance_expiry) FROM stdin;
\.


--
-- Data for Name: master_worksite; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.master_worksite (id, master_id, worksite_id, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: masters; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.masters (id, employee_id, user_id, is_active, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_07_24_000001_create_company_settings_table	1
5	2026_07_24_000002_create_exchange_rates_table	1
6	2026_07_24_000003_create_suppliers_table	1
7	2026_07_24_000004_create_clients_table	1
8	2026_07_24_204626_create_personal_access_tokens_table	2
9	2026_07_24_204627_create_activity_log_table	3
10	2026_07_24_204627_create_permission_tables	3
11	2026_07_24_204630_add_profile_fields_to_users_table	3
12	2026_07_24_204631_add_is_system_to_roles_table	3
13	2026_07_24_210001_create_payable_invoices_table	4
14	2026_07_24_210002_create_payments_table	4
15	2026_07_24_211001_create_receivable_invoices_table	5
16	2026_07_24_211002_create_receivable_deductions_table	5
17	2026_07_24_212001_create_bank_transactions_table	6
18	2026_07_26_220001_create_employees_table	7
19	2026_07_26_220002_create_salary_payments_table	7
20	2026_07_26_220003_create_worksites_table	7
21	2026_07_26_220004_create_masters_table	7
22	2026_07_26_220005_create_working_day_settings_table	7
23	2026_07_26_220006_create_attendance_records_table	7
24	2026_07_26_220007_create_worker_needs_table	7
25	2026_07_26_220008_create_production_records_table	8
26	2026_07_26_220009_create_machines_table	9
27	2026_07_26_220010_create_file_attachments_table	10
28	2026_07_26_220011_create_customs_documents_table	10
29	2026_07_26_220012_create_housing_tables	11
30	2026_07_27_220013_create_travel_tables	11
31	2026_07_27_220014_create_loans_table	11
32	2026_07_27_220015_add_expiry_dates_to_machines_table	12
33	2026_07_27_220016_create_notification_tables	12
34	2026_07_27_220017_create_import_tables	13
35	2026_07_27_220018_create_assistant_tables	14
36	2026_07_28_100001_add_performance_indexes	15
37	2026_07_28_100002_add_entity_to_import_batches	15
38	2026_07_29_000001_create_mines_and_projects_tables	16
\.


--
-- Data for Name: mines; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.mines (id, name, code, location, material_type, is_active, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: model_has_permissions; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.model_has_permissions (permission_id, model_type, model_id) FROM stdin;
\.


--
-- Data for Name: model_has_roles; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.model_has_roles (role_id, model_type, model_id) FROM stdin;
1	App\\Models\\User	1
\.


--
-- Data for Name: notification_rules; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.notification_rules (id, type, is_enabled, timing, days_before, severity, channels, recipient_roles, recipient_user_ids, config, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	payables.unpaid	t	weekly_summary	\N	info	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
2	payables.overdue	t	after_due	\N	warning	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
3	housing.rent_unpaid	t	days_before	3	warning	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
4	housing.bills_overdue	t	after_due	\N	warning	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
5	housing.contract_expiring	t	days_before	30	info	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
6	salaries.unpaid	t	monthly_summary	\N	warning	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
7	attendance.unapproved	t	same_day	\N	info	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
8	attendance.overtime_pending	t	same_day	\N	info	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
9	worker_needs.urgent_open	t	same_day	\N	critical	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
10	mining.production_missing	t	monthly_summary	\N	warning	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
11	machines.document_expiring	t	days_before	30	warning	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
12	customs.incomplete	t	same_day	\N	info	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
13	bank.unmatched	t	weekly_summary	\N	info	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
14	employees.missing_documents	t	weekly_summary	\N	info	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
15	employees.document_expiring	t	days_before	60	warning	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
16	custom.reminder	t	same_day	\N	info	["in_app"]	["Admin"]	[]	\N	\N	\N	seed	\N	2026-07-27 20:12:17	2026-07-27 20:12:17
\.


--
-- Data for Name: notifications; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.notifications (id, notification_rule_id, user_id, type, severity, subject_type, subject_id, data, due_date, period, status, read_at, dismissed_at, resolved_at, dedupe_key, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: password_reset_tokens; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.password_reset_tokens (email, token, created_at) FROM stdin;
\.


--
-- Data for Name: payable_invoices; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.payable_invoices (id, supplier_id, invoice_number, invoice_date, due_date, description, expense_category, currency, original_amount, paid_amount, remaining_amount, status, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
2	2	F2121	2026-07-27	\N	\N	Gorivo	EUR	521.12	521.12	0.00	paid	1	1	\N	\N	2026-07-27 21:51:55	2026-07-27 21:53:01
\.


--
-- Data for Name: payments; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.payments (id, payable_type, payable_id, amount, currency, payment_date, method, bank_transaction_id, reference, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
2	App\\Models\\ReceivableInvoice	1	2000.00	EUR	2026-07-08	nlb	\N	\N	1	1	\N	\N	2026-07-24 21:45:19	2026-07-24 21:45:19
4	App\\Models\\PayableInvoice	2	120.00	EUR	2026-07-27	nlb	\N	\N	1	1	\N	\N	2026-07-27 21:52:14	2026-07-27 21:52:14
5	App\\Models\\PayableInvoice	2	401.12	EUR	2026-07-27	nlb	\N	\N	1	1	\N	\N	2026-07-27 21:53:01	2026-07-27 21:53:01
6	App\\Models\\SalaryPayment	1	2000.00	EUR	2026-07-27	cash	\N	\N	1	1	\N	\N	2026-07-27 21:57:57	2026-07-27 21:57:57
7	App\\Models\\ReceivableInvoice	1	2500.00	EUR	2026-07-27	nlb	\N	\N	1	1	\N	\N	2026-07-27 22:09:08	2026-07-27 22:09:08
\.


--
-- Data for Name: permissions; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.permissions (id, name, guard_name, created_at, updated_at) FROM stdin;
1	users.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
2	roles.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
3	company.settings.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
4	exchange_rates.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
5	payables.view	web	2026-07-24 20:55:31	2026-07-24 20:55:31
6	payables.create	web	2026-07-24 20:55:31	2026-07-24 20:55:31
7	payables.approve	web	2026-07-24 20:55:31	2026-07-24 20:55:31
8	receivables.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
9	bank_transactions.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
10	employees.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
11	salary_payments.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
12	masters.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
13	worksites.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
14	attendance.submit	web	2026-07-24 20:55:31	2026-07-24 20:55:31
15	attendance.approve	web	2026-07-24 20:55:31	2026-07-24 20:55:31
16	overtime.approve	web	2026-07-24 20:55:31	2026-07-24 20:55:31
17	worker_needs.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
18	mining_production.submit	web	2026-07-24 20:55:31	2026-07-24 20:55:31
19	mining_production.approve	web	2026-07-24 20:55:31	2026-07-24 20:55:31
20	machines.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
21	customs_documents.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
22	housing.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
23	travel.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
24	loans.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
25	notifications.configure	web	2026-07-24 20:55:31	2026-07-24 20:55:31
26	imports.manage	web	2026-07-24 20:55:31	2026-07-24 20:55:31
27	assistant.use	web	2026-07-24 20:55:31	2026-07-24 20:55:31
28	reports.view	web	2026-07-24 20:55:31	2026-07-24 20:55:31
29	reports.export	web	2026-07-24 20:55:31	2026-07-24 20:55:31
30	audit_logs.view	web	2026-07-24 20:55:31	2026-07-24 20:55:31
\.


--
-- Data for Name: personal_access_tokens; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.personal_access_tokens (id, tokenable_type, tokenable_id, name, token, abilities, last_used_at, expires_at, created_at, updated_at) FROM stdin;
1	App\\Models\\User	1	api	e2a098090f80d1aa692ca5212bdcc0873248923bb42951f9d2d340e6f1b160f9	["*"]	2026-07-24 21:15:36	\N	2026-07-24 21:15:35	2026-07-24 21:15:36
2	App\\Models\\User	1	api	34a6290b78f199c95d6e926ba84c48ae9791f07f1365e1e56915a5294a52f77e	["*"]	2026-07-24 21:35:32	\N	2026-07-24 21:35:31	2026-07-24 21:35:32
3	App\\Models\\User	1	api	ac0f526dce4f9a45517c5f4a3036a68e50f12b8eb5940eecab67d3d835dc813d	["*"]	2026-07-24 21:45:20	\N	2026-07-24 21:45:19	2026-07-24 21:45:20
4	App\\Models\\User	1	api	8a8fbcbfcc9c3569f5f77a820085953d0896c1860bc990b8334895e1e3a6d126	["*"]	2026-07-24 21:54:43	\N	2026-07-24 21:54:42	2026-07-24 21:54:43
15	App\\Models\\User	1	api	069bc82767d93b6445914e197152ba9f32d951cd44a5440ba24cf1b1832b1d26	["*"]	2026-07-29 20:08:19	\N	2026-07-29 20:05:17	2026-07-29 20:08:19
12	App\\Models\\User	1	api	af9d6c97b9426a192bb2bdca1851b1176149c3cc5c2f84bde285b0b47d9cf278	["*"]	2026-07-28 15:59:55	\N	2026-07-28 15:59:42	2026-07-28 15:59:55
7	App\\Models\\User	1	api	a51d4e3a7112227cec3998c35786f6a1ee153cd5879be4eab1db671f0ea223a0	["*"]	2026-07-28 15:43:24	\N	2026-07-28 15:42:44	2026-07-28 15:43:24
8	App\\Models\\User	1	api	9d95c444af141921dc8eb037953e2257692df1a006653f3fdb8b2354f0f9d781	["*"]	2026-07-28 15:56:02	\N	2026-07-28 15:55:58	2026-07-28 15:56:02
9	App\\Models\\User	1	api	886de60044876c7181ad07b7b97cfa5948ec1f23ccdfdddef5d7f1d10afda61e	["*"]	2026-07-28 15:56:54	\N	2026-07-28 15:56:50	2026-07-28 15:56:54
10	App\\Models\\User	1	api	942c939badd58fe885fec0577134f619832f6a7e63d7c31b63b253872166f0e0	["*"]	2026-07-28 15:57:48	\N	2026-07-28 15:57:42	2026-07-28 15:57:48
11	App\\Models\\User	1	api	5d61e75ebfd18a08260425c738908ea8a3fa1114ffc1d48b509d367bbb6b1732	["*"]	2026-07-28 15:58:55	\N	2026-07-28 15:58:52	2026-07-28 15:58:55
14	App\\Models\\User	1	api	bb29dd683bcb7850c47d598a0ecec752774a6f0624586a16569fff2fb7d0dc20	["*"]	2026-07-29 19:47:51	\N	2026-07-29 19:46:59	2026-07-29 19:47:51
13	App\\Models\\User	1	api	16df5df540bd91be6c1e31ccd07e73cc8e8616b452238619f8c7a03a85b0314b	["*"]	2026-07-29 19:15:52	\N	2026-07-29 19:15:49	2026-07-29 19:15:52
\.


--
-- Data for Name: production_records; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.production_records (id, period_type, date, period_month, worksite_id, engineer_id, material_type, quantity, unit, quality_grade, attachment_path, approval_status, approved_at, approved_by, rejection_reason, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: projects; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.projects (id, name, code, client_id, start_date, end_date, is_active, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	1	\N	\N	\N	\N	t	\N	\N	migration	\N	2026-07-29 19:01:58	2026-07-29 19:01:58
\.


--
-- Data for Name: receivable_deductions; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.receivable_deductions (id, receivable_invoice_id, amount, deduction_date, reason, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	1	500.00	2026-07-09	material offset	1	1	\N	\N	2026-07-24 21:45:19	2026-07-24 21:45:19
\.


--
-- Data for Name: receivable_invoices; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.receivable_invoices (id, client_id, invoice_number, invoice_date, due_date, description, currency, invoice_amount, received_amount, deducted_amount, remaining_amount, status, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	1	U-2026-1	2026-07-02	\N	\N	EUR	5000.00	4500.00	500.00	0.00	paid	1	1	\N	\N	2026-07-24 21:45:19	2026-07-27 22:09:08
\.


--
-- Data for Name: rent_payments; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.rent_payments (id, house_id, month, currency, rent_amount_due, paid_amount, remaining_amount, status, cost_bearer, exception_reason, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: role_has_permissions; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.role_has_permissions (permission_id, role_id) FROM stdin;
1	1
2	1
3	1
4	1
5	1
6	1
7	1
8	1
9	1
10	1
11	1
12	1
13	1
14	1
15	1
16	1
17	1
18	1
19	1
20	1
21	1
22	1
23	1
24	1
25	1
26	1
27	1
28	1
29	1
30	1
1	2
2	2
3	2
4	2
5	2
6	2
7	2
8	2
9	2
10	2
11	2
12	2
13	2
14	2
15	2
16	2
17	2
18	2
19	2
20	2
21	2
22	2
23	2
24	2
25	2
26	2
27	2
28	2
29	2
30	2
5	5
28	5
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.roles (id, name, guard_name, created_at, updated_at, is_system) FROM stdin;
1	Super Admin	web	2026-07-24 20:55:32	2026-07-24 20:55:32	t
2	Admin	web	2026-07-24 20:55:32	2026-07-24 20:55:32	t
3	Administration Office Worker	web	2026-07-24 20:55:32	2026-07-24 20:55:32	t
4	Worker	web	2026-07-24 20:55:32	2026-07-24 20:55:32	t
5	Viewer	web	2026-07-24 20:55:32	2026-07-24 20:55:32	t
\.


--
-- Data for Name: salary_payments; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.salary_payments (id, employee_id, salary_month, currency, base_salary, adjustments, deductions, net_salary_due, paid_amount, remaining_amount, status, attachment_path, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	1	2026-07-01	EUR	2000.00	0.00	0.00	2000.00	2000.00	0.00	paid	\N	1	1	generated	\N	2026-07-27 21:57:40	2026-07-27 21:57:57
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
O3ek8Ifk8LmMZPhLGI4YPe8CKAdkkMDjBrWNb8p4	\N	127.0.0.1	curl/8.18.0	eyJfdG9rZW4iOiI1R3Q1czU4MlVhanhIQ1pqbFZyanQ3YTEyWFdGcWlucHdiMGRwZ1k0IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2xvY2FsaG9zdDo4MDAwIiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19	1785254473
\.


--
-- Data for Name: social_assistance_payments; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.social_assistance_payments (id, employee_id, person_name, payment_date, entitlement_year, currency, amount, exchange_rate, exchange_rate_date, amount_eur, method, bank_transaction_id, reason, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: suppliers; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.suppliers (id, name, tax_number, contact_name, phone, email, address, iban, is_active, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	Balkan Fuel DOO	\N	\N	\N	\N	\N	\N	t	1	1	\N	\N	2026-07-24 21:35:31	2026-07-24 21:35:31
2	Test Dobavljac	\N	\N	\N	\N	\N	\N	t	1	1	\N	\N	2026-07-27 21:51:27	2026-07-27 21:51:27
\.


--
-- Data for Name: travel_expenses; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.travel_expenses (id, employee_id, person_name, expense_date, period_month, expense_type, flight_ticket_id, currency, amount, exchange_rate, exchange_rate_date, amount_eur, paid_amount, remaining_amount, status, cost_status, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	1	Test	2026-07-27	2026-07-01	car	\N	EUR	50.00	\N	\N	50.00	0.00	50.00	unpaid	not_written	1	1	\N	\N	2026-07-27 22:04:12	2026-07-27 22:04:12
\.


--
-- Data for Name: user_notification_preferences; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.user_notification_preferences (id, user_id, type, is_enabled, channels, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.users (id, name, email, email_verified_at, password, remember_token, created_at, updated_at, locale, is_active) FROM stdin;
1	Super Admin	admin@globalmine.local	2026-07-24 20:55:32	$2y$12$Vj2gA0.xsb7CxdThowY.SOchOu4YK/8btHgY1kJ.erWgv6MgLC2Ve	RGiZmKgPCG	2026-07-24 20:55:32	2026-07-28 13:21:52	en	t
\.


--
-- Data for Name: utility_bills; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.utility_bills (id, house_id, bill_type, billing_period, amount, currency, due_date, paid_date, paid_amount, remaining_amount, status, cost_bearer, exception_reason, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: worker_needs; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.worker_needs (id, employee_id, worksite_id, date, need_type, description, priority, status, assigned_user_id, resolved_at, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
1	1	\N	2026-07-27	equipment	test	normal	resolved	\N	2026-07-27 21:59:24	1	1	\N	\N	2026-07-27 21:59:09	2026-07-27 21:59:24
\.


--
-- Data for Name: working_day_settings; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.working_day_settings (id, month, working_days, reason, created_by, updated_by, source, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: worksites; Type: TABLE DATA; Schema: public; Owner: adminismine
--

COPY public.worksites (id, name, location, client_id, is_active, created_by, updated_by, source, notes, created_at, updated_at, mine_id, project_id) FROM stdin;
1	Trst Gradiliste	NK	1	t	1	1	\N	\N	2026-07-27 22:00:45	2026-07-27 22:00:45	\N	1
\.


--
-- Name: activity_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.activity_log_id_seq', 36, true);


--
-- Name: ai_suggestions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.ai_suggestions_id_seq', 1, false);


--
-- Name: assistant_messages_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.assistant_messages_id_seq', 6, true);


--
-- Name: attendance_records_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.attendance_records_id_seq', 1, false);


--
-- Name: bank_transactions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.bank_transactions_id_seq', 1, true);


--
-- Name: clients_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.clients_id_seq', 1, true);


--
-- Name: company_settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.company_settings_id_seq', 1, true);


--
-- Name: customs_documents_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.customs_documents_id_seq', 1, false);


--
-- Name: employee_worksite_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.employee_worksite_id_seq', 1, true);


--
-- Name: employees_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.employees_id_seq', 1, true);


--
-- Name: exchange_rates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.exchange_rates_id_seq', 1, true);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 1, false);


--
-- Name: file_attachments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.file_attachments_id_seq', 1, false);


--
-- Name: flight_tickets_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.flight_tickets_id_seq', 1, false);


--
-- Name: house_occupancies_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.house_occupancies_id_seq', 1, true);


--
-- Name: houses_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.houses_id_seq', 1, true);


--
-- Name: housing_deductions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.housing_deductions_id_seq', 1, false);


--
-- Name: import_batches_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.import_batches_id_seq', 1, true);


--
-- Name: import_rows_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.import_rows_id_seq', 1, false);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.jobs_id_seq', 1, false);


--
-- Name: loans_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.loans_id_seq', 1, true);


--
-- Name: machines_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.machines_id_seq', 1, false);


--
-- Name: master_worksite_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.master_worksite_id_seq', 1, false);


--
-- Name: masters_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.masters_id_seq', 1, false);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.migrations_id_seq', 38, true);


--
-- Name: mines_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.mines_id_seq', 1, false);


--
-- Name: notification_rules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.notification_rules_id_seq', 16, true);


--
-- Name: notifications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.notifications_id_seq', 1, false);


--
-- Name: payable_invoices_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.payable_invoices_id_seq', 3, true);


--
-- Name: payments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.payments_id_seq', 7, true);


--
-- Name: permissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.permissions_id_seq', 30, true);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.personal_access_tokens_id_seq', 15, true);


--
-- Name: production_records_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.production_records_id_seq', 1, false);


--
-- Name: projects_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.projects_id_seq', 1, true);


--
-- Name: receivable_deductions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.receivable_deductions_id_seq', 1, true);


--
-- Name: receivable_invoices_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.receivable_invoices_id_seq', 1, true);


--
-- Name: rent_payments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.rent_payments_id_seq', 1, false);


--
-- Name: roles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.roles_id_seq', 5, true);


--
-- Name: salary_payments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.salary_payments_id_seq', 1, true);


--
-- Name: social_assistance_payments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.social_assistance_payments_id_seq', 1, false);


--
-- Name: suppliers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.suppliers_id_seq', 3, true);


--
-- Name: travel_expenses_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.travel_expenses_id_seq', 1, true);


--
-- Name: user_notification_preferences_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.user_notification_preferences_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.users_id_seq', 1, true);


--
-- Name: utility_bills_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.utility_bills_id_seq', 1, false);


--
-- Name: worker_needs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.worker_needs_id_seq', 1, true);


--
-- Name: working_day_settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.working_day_settings_id_seq', 1, false);


--
-- Name: worksites_id_seq; Type: SEQUENCE SET; Schema: public; Owner: adminismine
--

SELECT pg_catalog.setval('public.worksites_id_seq', 1, true);


--
-- Name: activity_log activity_log_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.activity_log
    ADD CONSTRAINT activity_log_pkey PRIMARY KEY (id);


--
-- Name: ai_suggestions ai_suggestions_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.ai_suggestions
    ADD CONSTRAINT ai_suggestions_pkey PRIMARY KEY (id);


--
-- Name: assistant_messages assistant_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.assistant_messages
    ADD CONSTRAINT assistant_messages_pkey PRIMARY KEY (id);


--
-- Name: attendance_records attendance_records_employee_id_date_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_employee_id_date_unique UNIQUE (employee_id, date);


--
-- Name: attendance_records attendance_records_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_pkey PRIMARY KEY (id);


--
-- Name: bank_transactions bank_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.bank_transactions
    ADD CONSTRAINT bank_transactions_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: clients clients_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_pkey PRIMARY KEY (id);


--
-- Name: company_settings company_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.company_settings
    ADD CONSTRAINT company_settings_pkey PRIMARY KEY (id);


--
-- Name: customs_documents customs_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.customs_documents
    ADD CONSTRAINT customs_documents_pkey PRIMARY KEY (id);


--
-- Name: employee_worksite employee_worksite_employee_id_worksite_id_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.employee_worksite
    ADD CONSTRAINT employee_worksite_employee_id_worksite_id_unique UNIQUE (employee_id, worksite_id);


--
-- Name: employee_worksite employee_worksite_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.employee_worksite
    ADD CONSTRAINT employee_worksite_pkey PRIMARY KEY (id);


--
-- Name: employees employees_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_pkey PRIMARY KEY (id);


--
-- Name: exchange_rates exchange_rates_base_currency_quote_currency_rate_date_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT exchange_rates_base_currency_quote_currency_rate_date_unique UNIQUE (base_currency, quote_currency, rate_date);


--
-- Name: exchange_rates exchange_rates_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT exchange_rates_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: file_attachments file_attachments_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.file_attachments
    ADD CONSTRAINT file_attachments_pkey PRIMARY KEY (id);


--
-- Name: flight_tickets flight_tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.flight_tickets
    ADD CONSTRAINT flight_tickets_pkey PRIMARY KEY (id);


--
-- Name: house_occupancies house_occupancies_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.house_occupancies
    ADD CONSTRAINT house_occupancies_pkey PRIMARY KEY (id);


--
-- Name: houses houses_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.houses
    ADD CONSTRAINT houses_pkey PRIMARY KEY (id);


--
-- Name: housing_deductions housing_deductions_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.housing_deductions
    ADD CONSTRAINT housing_deductions_pkey PRIMARY KEY (id);


--
-- Name: import_batches import_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.import_batches
    ADD CONSTRAINT import_batches_pkey PRIMARY KEY (id);


--
-- Name: import_rows import_rows_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.import_rows
    ADD CONSTRAINT import_rows_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: loans loans_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_pkey PRIMARY KEY (id);


--
-- Name: machines machines_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_pkey PRIMARY KEY (id);


--
-- Name: master_worksite master_worksite_master_id_worksite_id_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.master_worksite
    ADD CONSTRAINT master_worksite_master_id_worksite_id_unique UNIQUE (master_id, worksite_id);


--
-- Name: master_worksite master_worksite_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.master_worksite
    ADD CONSTRAINT master_worksite_pkey PRIMARY KEY (id);


--
-- Name: masters masters_employee_id_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.masters
    ADD CONSTRAINT masters_employee_id_unique UNIQUE (employee_id);


--
-- Name: masters masters_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.masters
    ADD CONSTRAINT masters_pkey PRIMARY KEY (id);


--
-- Name: masters masters_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.masters
    ADD CONSTRAINT masters_user_id_unique UNIQUE (user_id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: mines mines_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.mines
    ADD CONSTRAINT mines_pkey PRIMARY KEY (id);


--
-- Name: model_has_permissions model_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type);


--
-- Name: model_has_roles model_has_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type);


--
-- Name: notification_rules notification_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notification_rules
    ADD CONSTRAINT notification_rules_pkey PRIMARY KEY (id);


--
-- Name: notification_rules notification_rules_type_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notification_rules
    ADD CONSTRAINT notification_rules_type_unique UNIQUE (type);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_user_id_dedupe_key_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_user_id_dedupe_key_unique UNIQUE (user_id, dedupe_key);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: payable_invoices payable_invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.payable_invoices
    ADD CONSTRAINT payable_invoices_pkey PRIMARY KEY (id);


--
-- Name: payments payments_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: production_records production_records_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.production_records
    ADD CONSTRAINT production_records_pkey PRIMARY KEY (id);


--
-- Name: projects projects_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_pkey PRIMARY KEY (id);


--
-- Name: receivable_deductions receivable_deductions_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.receivable_deductions
    ADD CONSTRAINT receivable_deductions_pkey PRIMARY KEY (id);


--
-- Name: receivable_invoices receivable_invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.receivable_invoices
    ADD CONSTRAINT receivable_invoices_pkey PRIMARY KEY (id);


--
-- Name: rent_payments rent_payments_house_id_month_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.rent_payments
    ADD CONSTRAINT rent_payments_house_id_month_unique UNIQUE (house_id, month);


--
-- Name: rent_payments rent_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.rent_payments
    ADD CONSTRAINT rent_payments_pkey PRIMARY KEY (id);


--
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- Name: roles roles_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: salary_payments salary_payments_employee_id_salary_month_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.salary_payments
    ADD CONSTRAINT salary_payments_employee_id_salary_month_unique UNIQUE (employee_id, salary_month);


--
-- Name: salary_payments salary_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.salary_payments
    ADD CONSTRAINT salary_payments_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: social_assistance_payments social_assistance_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.social_assistance_payments
    ADD CONSTRAINT social_assistance_payments_pkey PRIMARY KEY (id);


--
-- Name: suppliers suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_pkey PRIMARY KEY (id);


--
-- Name: travel_expenses travel_expenses_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.travel_expenses
    ADD CONSTRAINT travel_expenses_pkey PRIMARY KEY (id);


--
-- Name: user_notification_preferences user_notification_preferences_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.user_notification_preferences
    ADD CONSTRAINT user_notification_preferences_pkey PRIMARY KEY (id);


--
-- Name: user_notification_preferences user_notification_preferences_user_id_type_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.user_notification_preferences
    ADD CONSTRAINT user_notification_preferences_user_id_type_unique UNIQUE (user_id, type);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: utility_bills utility_bills_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.utility_bills
    ADD CONSTRAINT utility_bills_pkey PRIMARY KEY (id);


--
-- Name: worker_needs worker_needs_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worker_needs
    ADD CONSTRAINT worker_needs_pkey PRIMARY KEY (id);


--
-- Name: working_day_settings working_day_settings_month_unique; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.working_day_settings
    ADD CONSTRAINT working_day_settings_month_unique UNIQUE (month);


--
-- Name: working_day_settings working_day_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.working_day_settings
    ADD CONSTRAINT working_day_settings_pkey PRIMARY KEY (id);


--
-- Name: worksites worksites_pkey; Type: CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worksites
    ADD CONSTRAINT worksites_pkey PRIMARY KEY (id);


--
-- Name: activity_log_log_name_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX activity_log_log_name_index ON public.activity_log USING btree (log_name);


--
-- Name: ai_suggestions_record_type_record_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX ai_suggestions_record_type_record_id_index ON public.ai_suggestions USING btree (record_type, record_id);


--
-- Name: ai_suggestions_user_id_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX ai_suggestions_user_id_status_index ON public.ai_suggestions USING btree (user_id, status);


--
-- Name: assistant_messages_user_id_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX assistant_messages_user_id_id_index ON public.assistant_messages USING btree (user_id, id);


--
-- Name: attendance_records_approval_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX attendance_records_approval_status_index ON public.attendance_records USING btree (approval_status);


--
-- Name: attendance_records_date_worksite_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX attendance_records_date_worksite_id_index ON public.attendance_records USING btree (date, worksite_id);


--
-- Name: attendance_records_master_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX attendance_records_master_id_index ON public.attendance_records USING btree (master_id);


--
-- Name: bank_transactions_category_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX bank_transactions_category_index ON public.bank_transactions USING btree (category);


--
-- Name: bank_transactions_client_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX bank_transactions_client_id_index ON public.bank_transactions USING btree (client_id);


--
-- Name: bank_transactions_date_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX bank_transactions_date_id_index ON public.bank_transactions USING btree (date, id);


--
-- Name: bank_transactions_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX bank_transactions_date_index ON public.bank_transactions USING btree (date);


--
-- Name: bank_transactions_signature_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX bank_transactions_signature_index ON public.bank_transactions USING btree (date, cash_amount, nlb_amount, lovcen_amount);


--
-- Name: bank_transactions_supplier_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX bank_transactions_supplier_id_index ON public.bank_transactions USING btree (supplier_id);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: causer; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX causer ON public.activity_log USING btree (causer_type, causer_id);


--
-- Name: clients_name_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX clients_name_index ON public.clients USING btree (name);


--
-- Name: customs_documents_cmr_number_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX customs_documents_cmr_number_index ON public.customs_documents USING btree (cmr_number);


--
-- Name: customs_documents_customs_company_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX customs_documents_customs_company_id_index ON public.customs_documents USING btree (customs_company_id);


--
-- Name: customs_documents_document_number_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX customs_documents_document_number_index ON public.customs_documents USING btree (document_number);


--
-- Name: customs_documents_document_type_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX customs_documents_document_type_index ON public.customs_documents USING btree (document_type);


--
-- Name: customs_documents_machine_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX customs_documents_machine_id_index ON public.customs_documents USING btree (machine_id);


--
-- Name: customs_documents_payable_invoice_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX customs_documents_payable_invoice_id_index ON public.customs_documents USING btree (payable_invoice_id);


--
-- Name: customs_documents_receivable_invoice_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX customs_documents_receivable_invoice_id_index ON public.customs_documents USING btree (receivable_invoice_id);


--
-- Name: customs_documents_shipment_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX customs_documents_shipment_date_index ON public.customs_documents USING btree (shipment_date);


--
-- Name: customs_documents_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX customs_documents_status_index ON public.customs_documents USING btree (status);


--
-- Name: employees_bank_account_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX employees_bank_account_status_index ON public.employees USING btree (bank_account_status);


--
-- Name: employees_last_name_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX employees_last_name_index ON public.employees USING btree (last_name);


--
-- Name: employees_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX employees_status_index ON public.employees USING btree (status);


--
-- Name: failed_jobs_connection_queue_failed_at_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX failed_jobs_connection_queue_failed_at_index ON public.failed_jobs USING btree (connection, queue, failed_at);


--
-- Name: file_attachments_attachable_kind_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX file_attachments_attachable_kind_index ON public.file_attachments USING btree (attachable_type, attachable_id, kind);


--
-- Name: file_attachments_attachable_type_attachable_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX file_attachments_attachable_type_attachable_id_index ON public.file_attachments USING btree (attachable_type, attachable_id);


--
-- Name: flight_tickets_cost_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX flight_tickets_cost_status_index ON public.flight_tickets USING btree (cost_status);


--
-- Name: flight_tickets_employee_id_ticket_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX flight_tickets_employee_id_ticket_date_index ON public.flight_tickets USING btree (employee_id, ticket_date);


--
-- Name: flight_tickets_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX flight_tickets_status_index ON public.flight_tickets USING btree (status);


--
-- Name: flight_tickets_ticket_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX flight_tickets_ticket_date_index ON public.flight_tickets USING btree (ticket_date);


--
-- Name: house_occupancies_employee_id_moved_out_at_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX house_occupancies_employee_id_moved_out_at_index ON public.house_occupancies USING btree (employee_id, moved_out_at);


--
-- Name: house_occupancies_house_id_moved_out_at_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX house_occupancies_house_id_moved_out_at_index ON public.house_occupancies USING btree (house_id, moved_out_at);


--
-- Name: houses_is_active_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX houses_is_active_index ON public.houses USING btree (is_active);


--
-- Name: houses_name_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX houses_name_index ON public.houses USING btree (name);


--
-- Name: housing_deductions_employee_id_month_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX housing_deductions_employee_id_month_index ON public.housing_deductions USING btree (employee_id, month);


--
-- Name: housing_deductions_house_id_month_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX housing_deductions_house_id_month_index ON public.housing_deductions USING btree (house_id, month);


--
-- Name: import_batches_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX import_batches_status_index ON public.import_batches USING btree (status);


--
-- Name: import_rows_import_batch_id_sheet_name_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX import_rows_import_batch_id_sheet_name_index ON public.import_rows USING btree (import_batch_id, sheet_name);


--
-- Name: import_rows_import_batch_id_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX import_rows_import_batch_id_status_index ON public.import_rows USING btree (import_batch_id, status);


--
-- Name: import_rows_record_type_record_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX import_rows_record_type_record_id_index ON public.import_rows USING btree (record_type, record_id);


--
-- Name: import_rows_target_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX import_rows_target_index ON public.import_rows USING btree (target);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: loans_client_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX loans_client_id_index ON public.loans USING btree (client_id);


--
-- Name: loans_counterparty_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX loans_counterparty_index ON public.loans USING btree (counterparty);


--
-- Name: loans_direction_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX loans_direction_index ON public.loans USING btree (direction);


--
-- Name: loans_employee_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX loans_employee_id_index ON public.loans USING btree (employee_id);


--
-- Name: loans_loan_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX loans_loan_date_index ON public.loans USING btree (loan_date);


--
-- Name: loans_reference_number_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX loans_reference_number_index ON public.loans USING btree (reference_number);


--
-- Name: loans_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX loans_status_index ON public.loans USING btree (status);


--
-- Name: loans_supplier_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX loans_supplier_id_index ON public.loans USING btree (supplier_id);


--
-- Name: machines_machine_type_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX machines_machine_type_index ON public.machines USING btree (machine_type);


--
-- Name: machines_payable_invoice_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX machines_payable_invoice_id_index ON public.machines USING btree (payable_invoice_id);


--
-- Name: machines_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX machines_status_index ON public.machines USING btree (status);


--
-- Name: machines_supplier_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX machines_supplier_id_index ON public.machines USING btree (supplier_id);


--
-- Name: machines_worksite_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX machines_worksite_id_index ON public.machines USING btree (worksite_id);


--
-- Name: mines_name_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX mines_name_index ON public.mines USING btree (name);


--
-- Name: model_has_permissions_model_id_model_type_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX model_has_permissions_model_id_model_type_index ON public.model_has_permissions USING btree (model_id, model_type);


--
-- Name: model_has_roles_model_id_model_type_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX model_has_roles_model_id_model_type_index ON public.model_has_roles USING btree (model_id, model_type);


--
-- Name: notification_rules_is_enabled_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX notification_rules_is_enabled_index ON public.notification_rules USING btree (is_enabled);


--
-- Name: notifications_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX notifications_subject_type_subject_id_index ON public.notifications USING btree (subject_type, subject_id);


--
-- Name: notifications_type_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX notifications_type_index ON public.notifications USING btree (type);


--
-- Name: notifications_user_id_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX notifications_user_id_status_index ON public.notifications USING btree (user_id, status);


--
-- Name: payable_invoices_due_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX payable_invoices_due_date_index ON public.payable_invoices USING btree (due_date);


--
-- Name: payable_invoices_invoice_date_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX payable_invoices_invoice_date_id_index ON public.payable_invoices USING btree (invoice_date, id);


--
-- Name: payable_invoices_invoice_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX payable_invoices_invoice_date_index ON public.payable_invoices USING btree (invoice_date);


--
-- Name: payable_invoices_status_due_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX payable_invoices_status_due_date_index ON public.payable_invoices USING btree (status, due_date);


--
-- Name: payable_invoices_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX payable_invoices_status_index ON public.payable_invoices USING btree (status);


--
-- Name: payable_invoices_supplier_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX payable_invoices_supplier_id_index ON public.payable_invoices USING btree (supplier_id);


--
-- Name: payments_bank_transaction_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX payments_bank_transaction_id_index ON public.payments USING btree (bank_transaction_id);


--
-- Name: payments_payable_type_payable_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX payments_payable_type_payable_id_index ON public.payments USING btree (payable_type, payable_id);


--
-- Name: payments_payment_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX payments_payment_date_index ON public.payments USING btree (payment_date);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: production_records_approval_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX production_records_approval_status_index ON public.production_records USING btree (approval_status);


--
-- Name: production_records_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX production_records_date_index ON public.production_records USING btree (date);


--
-- Name: production_records_material_type_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX production_records_material_type_index ON public.production_records USING btree (material_type);


--
-- Name: production_records_period_month_worksite_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX production_records_period_month_worksite_id_index ON public.production_records USING btree (period_month, worksite_id);


--
-- Name: projects_name_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX projects_name_index ON public.projects USING btree (name);


--
-- Name: receivable_deductions_invoice_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX receivable_deductions_invoice_id_index ON public.receivable_deductions USING btree (receivable_invoice_id);


--
-- Name: receivable_invoices_client_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX receivable_invoices_client_id_index ON public.receivable_invoices USING btree (client_id);


--
-- Name: receivable_invoices_due_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX receivable_invoices_due_date_index ON public.receivable_invoices USING btree (due_date);


--
-- Name: receivable_invoices_invoice_date_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX receivable_invoices_invoice_date_id_index ON public.receivable_invoices USING btree (invoice_date, id);


--
-- Name: receivable_invoices_invoice_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX receivable_invoices_invoice_date_index ON public.receivable_invoices USING btree (invoice_date);


--
-- Name: receivable_invoices_status_client_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX receivable_invoices_status_client_id_index ON public.receivable_invoices USING btree (status, client_id);


--
-- Name: receivable_invoices_status_due_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX receivable_invoices_status_due_date_index ON public.receivable_invoices USING btree (status, due_date);


--
-- Name: receivable_invoices_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX receivable_invoices_status_index ON public.receivable_invoices USING btree (status);


--
-- Name: rent_payments_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX rent_payments_status_index ON public.rent_payments USING btree (status);


--
-- Name: salary_payments_salary_month_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX salary_payments_salary_month_index ON public.salary_payments USING btree (salary_month);


--
-- Name: salary_payments_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX salary_payments_status_index ON public.salary_payments USING btree (status);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: social_assistance_payments_employee_id_entitlement_year_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX social_assistance_payments_employee_id_entitlement_year_index ON public.social_assistance_payments USING btree (employee_id, entitlement_year);


--
-- Name: social_assistance_payments_payment_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX social_assistance_payments_payment_date_index ON public.social_assistance_payments USING btree (payment_date);


--
-- Name: subject; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX subject ON public.activity_log USING btree (subject_type, subject_id);


--
-- Name: suppliers_name_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX suppliers_name_index ON public.suppliers USING btree (name);


--
-- Name: travel_expenses_employee_id_period_month_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX travel_expenses_employee_id_period_month_index ON public.travel_expenses USING btree (employee_id, period_month);


--
-- Name: travel_expenses_expense_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX travel_expenses_expense_date_index ON public.travel_expenses USING btree (expense_date);


--
-- Name: travel_expenses_expense_type_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX travel_expenses_expense_type_index ON public.travel_expenses USING btree (expense_type);


--
-- Name: travel_expenses_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX travel_expenses_status_index ON public.travel_expenses USING btree (status);


--
-- Name: utility_bills_bill_type_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX utility_bills_bill_type_index ON public.utility_bills USING btree (bill_type);


--
-- Name: utility_bills_house_id_billing_period_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX utility_bills_house_id_billing_period_index ON public.utility_bills USING btree (house_id, billing_period);


--
-- Name: utility_bills_period_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX utility_bills_period_status_index ON public.utility_bills USING btree (billing_period, status);


--
-- Name: utility_bills_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX utility_bills_status_index ON public.utility_bills USING btree (status);


--
-- Name: worker_needs_assigned_user_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX worker_needs_assigned_user_id_index ON public.worker_needs USING btree (assigned_user_id);


--
-- Name: worker_needs_date_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX worker_needs_date_index ON public.worker_needs USING btree (date);


--
-- Name: worker_needs_employee_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX worker_needs_employee_id_index ON public.worker_needs USING btree (employee_id);


--
-- Name: worker_needs_priority_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX worker_needs_priority_index ON public.worker_needs USING btree (priority);


--
-- Name: worker_needs_status_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX worker_needs_status_index ON public.worker_needs USING btree (status);


--
-- Name: worker_needs_status_resolved_at_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX worker_needs_status_resolved_at_index ON public.worker_needs USING btree (status, resolved_at);


--
-- Name: worker_needs_worksite_id_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX worker_needs_worksite_id_index ON public.worker_needs USING btree (worksite_id);


--
-- Name: worksites_name_index; Type: INDEX; Schema: public; Owner: adminismine
--

CREATE INDEX worksites_name_index ON public.worksites USING btree (name);


--
-- Name: ai_suggestions ai_suggestions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.ai_suggestions
    ADD CONSTRAINT ai_suggestions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ai_suggestions ai_suggestions_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.ai_suggestions
    ADD CONSTRAINT ai_suggestions_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ai_suggestions ai_suggestions_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.ai_suggestions
    ADD CONSTRAINT ai_suggestions_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: assistant_messages assistant_messages_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.assistant_messages
    ADD CONSTRAINT assistant_messages_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: attendance_records attendance_records_approved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: attendance_records attendance_records_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: attendance_records attendance_records_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE RESTRICT;


--
-- Name: attendance_records attendance_records_master_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_master_id_foreign FOREIGN KEY (master_id) REFERENCES public.masters(id) ON DELETE SET NULL;


--
-- Name: attendance_records attendance_records_submitted_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_submitted_by_foreign FOREIGN KEY (submitted_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: attendance_records attendance_records_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: attendance_records attendance_records_worksite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_worksite_id_foreign FOREIGN KEY (worksite_id) REFERENCES public.worksites(id) ON DELETE RESTRICT;


--
-- Name: bank_transactions bank_transactions_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.bank_transactions
    ADD CONSTRAINT bank_transactions_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE SET NULL;


--
-- Name: bank_transactions bank_transactions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.bank_transactions
    ADD CONSTRAINT bank_transactions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: bank_transactions bank_transactions_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.bank_transactions
    ADD CONSTRAINT bank_transactions_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE SET NULL;


--
-- Name: bank_transactions bank_transactions_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.bank_transactions
    ADD CONSTRAINT bank_transactions_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: clients clients_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: clients clients_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: company_settings company_settings_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.company_settings
    ADD CONSTRAINT company_settings_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: company_settings company_settings_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.company_settings
    ADD CONSTRAINT company_settings_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: customs_documents customs_documents_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.customs_documents
    ADD CONSTRAINT customs_documents_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE SET NULL;


--
-- Name: customs_documents customs_documents_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.customs_documents
    ADD CONSTRAINT customs_documents_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: customs_documents customs_documents_customs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.customs_documents
    ADD CONSTRAINT customs_documents_customs_company_id_foreign FOREIGN KEY (customs_company_id) REFERENCES public.suppliers(id) ON DELETE SET NULL;


--
-- Name: customs_documents customs_documents_machine_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.customs_documents
    ADD CONSTRAINT customs_documents_machine_id_foreign FOREIGN KEY (machine_id) REFERENCES public.machines(id) ON DELETE SET NULL;


--
-- Name: customs_documents customs_documents_payable_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.customs_documents
    ADD CONSTRAINT customs_documents_payable_invoice_id_foreign FOREIGN KEY (payable_invoice_id) REFERENCES public.payable_invoices(id) ON DELETE SET NULL;


--
-- Name: customs_documents customs_documents_production_record_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.customs_documents
    ADD CONSTRAINT customs_documents_production_record_id_foreign FOREIGN KEY (production_record_id) REFERENCES public.production_records(id) ON DELETE SET NULL;


--
-- Name: customs_documents customs_documents_receivable_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.customs_documents
    ADD CONSTRAINT customs_documents_receivable_invoice_id_foreign FOREIGN KEY (receivable_invoice_id) REFERENCES public.receivable_invoices(id) ON DELETE SET NULL;


--
-- Name: customs_documents customs_documents_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.customs_documents
    ADD CONSTRAINT customs_documents_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE SET NULL;


--
-- Name: customs_documents customs_documents_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.customs_documents
    ADD CONSTRAINT customs_documents_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: employee_worksite employee_worksite_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.employee_worksite
    ADD CONSTRAINT employee_worksite_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: employee_worksite employee_worksite_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.employee_worksite
    ADD CONSTRAINT employee_worksite_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE CASCADE;


--
-- Name: employee_worksite employee_worksite_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.employee_worksite
    ADD CONSTRAINT employee_worksite_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: employee_worksite employee_worksite_worksite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.employee_worksite
    ADD CONSTRAINT employee_worksite_worksite_id_foreign FOREIGN KEY (worksite_id) REFERENCES public.worksites(id) ON DELETE CASCADE;


--
-- Name: employees employees_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: employees employees_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: exchange_rates exchange_rates_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT exchange_rates_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: exchange_rates exchange_rates_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT exchange_rates_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: file_attachments file_attachments_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.file_attachments
    ADD CONSTRAINT file_attachments_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: file_attachments file_attachments_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.file_attachments
    ADD CONSTRAINT file_attachments_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: flight_tickets flight_tickets_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.flight_tickets
    ADD CONSTRAINT flight_tickets_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: flight_tickets flight_tickets_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.flight_tickets
    ADD CONSTRAINT flight_tickets_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE SET NULL;


--
-- Name: flight_tickets flight_tickets_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.flight_tickets
    ADD CONSTRAINT flight_tickets_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: house_occupancies house_occupancies_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.house_occupancies
    ADD CONSTRAINT house_occupancies_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: house_occupancies house_occupancies_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.house_occupancies
    ADD CONSTRAINT house_occupancies_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE RESTRICT;


--
-- Name: house_occupancies house_occupancies_house_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.house_occupancies
    ADD CONSTRAINT house_occupancies_house_id_foreign FOREIGN KEY (house_id) REFERENCES public.houses(id) ON DELETE RESTRICT;


--
-- Name: house_occupancies house_occupancies_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.house_occupancies
    ADD CONSTRAINT house_occupancies_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: houses houses_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.houses
    ADD CONSTRAINT houses_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: houses houses_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.houses
    ADD CONSTRAINT houses_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: housing_deductions housing_deductions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.housing_deductions
    ADD CONSTRAINT housing_deductions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: housing_deductions housing_deductions_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.housing_deductions
    ADD CONSTRAINT housing_deductions_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE RESTRICT;


--
-- Name: housing_deductions housing_deductions_house_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.housing_deductions
    ADD CONSTRAINT housing_deductions_house_id_foreign FOREIGN KEY (house_id) REFERENCES public.houses(id) ON DELETE RESTRICT;


--
-- Name: housing_deductions housing_deductions_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.housing_deductions
    ADD CONSTRAINT housing_deductions_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: housing_deductions housing_deductions_utility_bill_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.housing_deductions
    ADD CONSTRAINT housing_deductions_utility_bill_id_foreign FOREIGN KEY (utility_bill_id) REFERENCES public.utility_bills(id) ON DELETE SET NULL;


--
-- Name: import_batches import_batches_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.import_batches
    ADD CONSTRAINT import_batches_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: import_batches import_batches_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.import_batches
    ADD CONSTRAINT import_batches_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: import_rows import_rows_import_batch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.import_rows
    ADD CONSTRAINT import_rows_import_batch_id_foreign FOREIGN KEY (import_batch_id) REFERENCES public.import_batches(id) ON DELETE CASCADE;


--
-- Name: loans loans_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE SET NULL;


--
-- Name: loans loans_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: loans loans_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE SET NULL;


--
-- Name: loans loans_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE SET NULL;


--
-- Name: loans loans_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: machines machines_bank_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_bank_transaction_id_foreign FOREIGN KEY (bank_transaction_id) REFERENCES public.bank_transactions(id) ON DELETE SET NULL;


--
-- Name: machines machines_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: machines machines_payable_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_payable_invoice_id_foreign FOREIGN KEY (payable_invoice_id) REFERENCES public.payable_invoices(id) ON DELETE SET NULL;


--
-- Name: machines machines_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE SET NULL;


--
-- Name: machines machines_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: machines machines_worksite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_worksite_id_foreign FOREIGN KEY (worksite_id) REFERENCES public.worksites(id) ON DELETE SET NULL;


--
-- Name: master_worksite master_worksite_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.master_worksite
    ADD CONSTRAINT master_worksite_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: master_worksite master_worksite_master_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.master_worksite
    ADD CONSTRAINT master_worksite_master_id_foreign FOREIGN KEY (master_id) REFERENCES public.masters(id) ON DELETE CASCADE;


--
-- Name: master_worksite master_worksite_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.master_worksite
    ADD CONSTRAINT master_worksite_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: master_worksite master_worksite_worksite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.master_worksite
    ADD CONSTRAINT master_worksite_worksite_id_foreign FOREIGN KEY (worksite_id) REFERENCES public.worksites(id) ON DELETE CASCADE;


--
-- Name: masters masters_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.masters
    ADD CONSTRAINT masters_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: masters masters_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.masters
    ADD CONSTRAINT masters_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE RESTRICT;


--
-- Name: masters masters_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.masters
    ADD CONSTRAINT masters_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: masters masters_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.masters
    ADD CONSTRAINT masters_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: mines mines_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.mines
    ADD CONSTRAINT mines_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: mines mines_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.mines
    ADD CONSTRAINT mines_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: model_has_permissions model_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: model_has_roles model_has_roles_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: notification_rules notification_rules_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notification_rules
    ADD CONSTRAINT notification_rules_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: notification_rules notification_rules_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notification_rules
    ADD CONSTRAINT notification_rules_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: notifications notifications_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: notifications notifications_notification_rule_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_notification_rule_id_foreign FOREIGN KEY (notification_rule_id) REFERENCES public.notification_rules(id) ON DELETE SET NULL;


--
-- Name: notifications notifications_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: notifications notifications_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: payable_invoices payable_invoices_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.payable_invoices
    ADD CONSTRAINT payable_invoices_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: payable_invoices payable_invoices_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.payable_invoices
    ADD CONSTRAINT payable_invoices_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE RESTRICT;


--
-- Name: payable_invoices payable_invoices_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.payable_invoices
    ADD CONSTRAINT payable_invoices_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: payments payments_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: payments payments_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: production_records production_records_approved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.production_records
    ADD CONSTRAINT production_records_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: production_records production_records_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.production_records
    ADD CONSTRAINT production_records_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: production_records production_records_engineer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.production_records
    ADD CONSTRAINT production_records_engineer_id_foreign FOREIGN KEY (engineer_id) REFERENCES public.employees(id) ON DELETE SET NULL;


--
-- Name: production_records production_records_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.production_records
    ADD CONSTRAINT production_records_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: production_records production_records_worksite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.production_records
    ADD CONSTRAINT production_records_worksite_id_foreign FOREIGN KEY (worksite_id) REFERENCES public.worksites(id) ON DELETE RESTRICT;


--
-- Name: projects projects_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE SET NULL;


--
-- Name: projects projects_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: projects projects_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: receivable_deductions receivable_deductions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.receivable_deductions
    ADD CONSTRAINT receivable_deductions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: receivable_deductions receivable_deductions_receivable_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.receivable_deductions
    ADD CONSTRAINT receivable_deductions_receivable_invoice_id_foreign FOREIGN KEY (receivable_invoice_id) REFERENCES public.receivable_invoices(id) ON DELETE CASCADE;


--
-- Name: receivable_deductions receivable_deductions_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.receivable_deductions
    ADD CONSTRAINT receivable_deductions_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: receivable_invoices receivable_invoices_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.receivable_invoices
    ADD CONSTRAINT receivable_invoices_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE RESTRICT;


--
-- Name: receivable_invoices receivable_invoices_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.receivable_invoices
    ADD CONSTRAINT receivable_invoices_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: receivable_invoices receivable_invoices_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.receivable_invoices
    ADD CONSTRAINT receivable_invoices_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: rent_payments rent_payments_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.rent_payments
    ADD CONSTRAINT rent_payments_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: rent_payments rent_payments_house_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.rent_payments
    ADD CONSTRAINT rent_payments_house_id_foreign FOREIGN KEY (house_id) REFERENCES public.houses(id) ON DELETE RESTRICT;


--
-- Name: rent_payments rent_payments_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.rent_payments
    ADD CONSTRAINT rent_payments_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: role_has_permissions role_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: salary_payments salary_payments_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.salary_payments
    ADD CONSTRAINT salary_payments_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: salary_payments salary_payments_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.salary_payments
    ADD CONSTRAINT salary_payments_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE RESTRICT;


--
-- Name: salary_payments salary_payments_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.salary_payments
    ADD CONSTRAINT salary_payments_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: social_assistance_payments social_assistance_payments_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.social_assistance_payments
    ADD CONSTRAINT social_assistance_payments_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: social_assistance_payments social_assistance_payments_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.social_assistance_payments
    ADD CONSTRAINT social_assistance_payments_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE SET NULL;


--
-- Name: social_assistance_payments social_assistance_payments_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.social_assistance_payments
    ADD CONSTRAINT social_assistance_payments_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: suppliers suppliers_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: suppliers suppliers_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: travel_expenses travel_expenses_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.travel_expenses
    ADD CONSTRAINT travel_expenses_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: travel_expenses travel_expenses_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.travel_expenses
    ADD CONSTRAINT travel_expenses_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE SET NULL;


--
-- Name: travel_expenses travel_expenses_flight_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.travel_expenses
    ADD CONSTRAINT travel_expenses_flight_ticket_id_foreign FOREIGN KEY (flight_ticket_id) REFERENCES public.flight_tickets(id) ON DELETE SET NULL;


--
-- Name: travel_expenses travel_expenses_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.travel_expenses
    ADD CONSTRAINT travel_expenses_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: user_notification_preferences user_notification_preferences_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.user_notification_preferences
    ADD CONSTRAINT user_notification_preferences_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: user_notification_preferences user_notification_preferences_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.user_notification_preferences
    ADD CONSTRAINT user_notification_preferences_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: user_notification_preferences user_notification_preferences_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.user_notification_preferences
    ADD CONSTRAINT user_notification_preferences_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: utility_bills utility_bills_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.utility_bills
    ADD CONSTRAINT utility_bills_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: utility_bills utility_bills_house_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.utility_bills
    ADD CONSTRAINT utility_bills_house_id_foreign FOREIGN KEY (house_id) REFERENCES public.houses(id) ON DELETE RESTRICT;


--
-- Name: utility_bills utility_bills_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.utility_bills
    ADD CONSTRAINT utility_bills_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: worker_needs worker_needs_assigned_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worker_needs
    ADD CONSTRAINT worker_needs_assigned_user_id_foreign FOREIGN KEY (assigned_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: worker_needs worker_needs_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worker_needs
    ADD CONSTRAINT worker_needs_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: worker_needs worker_needs_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worker_needs
    ADD CONSTRAINT worker_needs_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE RESTRICT;


--
-- Name: worker_needs worker_needs_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worker_needs
    ADD CONSTRAINT worker_needs_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: worker_needs worker_needs_worksite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worker_needs
    ADD CONSTRAINT worker_needs_worksite_id_foreign FOREIGN KEY (worksite_id) REFERENCES public.worksites(id) ON DELETE SET NULL;


--
-- Name: working_day_settings working_day_settings_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.working_day_settings
    ADD CONSTRAINT working_day_settings_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: working_day_settings working_day_settings_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.working_day_settings
    ADD CONSTRAINT working_day_settings_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: worksites worksites_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worksites
    ADD CONSTRAINT worksites_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE SET NULL;


--
-- Name: worksites worksites_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worksites
    ADD CONSTRAINT worksites_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: worksites worksites_mine_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worksites
    ADD CONSTRAINT worksites_mine_id_foreign FOREIGN KEY (mine_id) REFERENCES public.mines(id) ON DELETE SET NULL;


--
-- Name: worksites worksites_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worksites
    ADD CONSTRAINT worksites_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE SET NULL;


--
-- Name: worksites worksites_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: adminismine
--

ALTER TABLE ONLY public.worksites
    ADD CONSTRAINT worksites_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict MdW49gSylXzYvexNUTjCryiOT3lt1w2ZcMWdrHgBlOv1CQFRtQx3TS24Vzy7j8N

