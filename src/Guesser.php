<?php
/**
 * เดาความหมายของคอลัมน์ด้วยกฎในระบบเอง (ไม่ส่งข้อมูลออกนอกเครื่องแม่ข่าย)
 *
 *  1) same     คอลัมน์ชื่อเดียวกันในโปรเจ็คที่มีคนเขียนความหมายไว้แล้ว
 *  2) dict     พจนานุกรมชื่อคอลัมน์ที่พบบ่อย (ทั่วไป + ระบบโรงพยาบาล)
 *  3) pattern  รูปแบบชื่อ เช่น *_date, *_id, is_*
 *
 * และอ่าน "ค่าจริง" จากฐานข้อมูลต้นทางแบบสรุป (ค่าที่ไม่ซ้ำ + จำนวนแถว) เพื่อเติมช่องค่าที่เป็นไปได้
 */
declare(strict_types=1);

if (!defined('DD_APP')) {
    http_response_code(403);
    exit;
}

class Guesser
{
    /** ค่า described_by ของรายการที่ระบบเดาให้ทั้งโปรเจ็ค ใช้แยกออกจากสิ่งที่คนเขียน และใช้ย้อนกลับ */
    const BY = 'เดาอัตโนมัติ';

    const SOURCES = ['same', 'dict', 'ref', 'pattern'];

    const LABELS = [
        'same'    => 'ชื่อเดียวกับคอลัมน์ที่มีความหมายแล้ว',
        'dict'    => 'พจนานุกรมชื่อที่พบบ่อย',
        'ref'     => 'ตารางที่คอลัมน์นี้อ้างอิงถึง',
        'pattern' => 'รูปแบบชื่อคอลัมน์',
    ];

    /**
     * ชื่อกว้าง ๆ ที่ความหมายขึ้นกับตาราง: เติมหัวเรื่องของตารางต่อท้าย เช่น name ในตารางแผนก = "ชื่อแผนก"
     * %s = หัวเรื่องของตาราง (จากคำอธิบายตาราง หรือแปลจากชื่อตาราง)
     */
    const CTX = [
        'id' => 'รหัส%s', 'code' => 'รหัส%s', 'name' => 'ชื่อ%s', 'title' => 'หัวข้อของ%s', 'status' => 'สถานะของ%s', 'type' => 'ประเภทของ%s',
        'note' => 'หมายเหตุของ%s', 'notes' => 'หมายเหตุของ%s', 'remark' => 'หมายเหตุของ%s', 'remarks' => 'หมายเหตุของ%s', 'comment' => 'หมายเหตุของ%s',
        'detail' => 'รายละเอียดของ%s', 'details' => 'รายละเอียดของ%s', 'description' => 'คำอธิบายของ%s', 'reason' => 'เหตุผลของ%s',
        'seq' => 'ลำดับที่ของ%s', 'no' => 'เลขที่ของ%s', 'number' => 'เลขที่ของ%s', 'date' => 'วันที่ของ%s', 'time' => 'เวลาของ%s',
        'amount' => 'จำนวนเงินของ%s', 'qty' => 'จำนวนของ%s', 'price' => 'ราคาของ%s', 'total' => 'ยอดรวมของ%s', 'level' => 'ระดับของ%s',
        'result' => 'ผลของ%s', 'value' => 'ค่าของ%s', 'unit' => 'หน่วยนับของ%s',
    ];

    /** ชื่อกว้างเกินไป ความหมายขึ้นกับตาราง จึงไม่คัดลอกจากคอลัมน์ชื่อเดียวกันในตารางอื่น */
    const GENERIC = [
        'id', 'code', 'name', 'status', 'type', 'note', 'notes', 'remark', 'remarks', 'detail', 'details', 'description',
        'value', 'seq', 'no', 'flag', 'title', 'date', 'time', 'amount', 'qty', 'price', 'total', 'level', 'text',
        'data', 'result', 'comment', 'reason', 'active', 'number', 'num', 'key', 'label', 'message', 'unit',
    ];

    const DICT = [
        // ---- ทั่วไป
        'id' => 'รหัสรายการ', 'code' => 'รหัส', 'name' => 'ชื่อ', 'title' => 'หัวข้อ', 'status' => 'สถานะ', 'type' => 'ประเภท',
        'note' => 'หมายเหตุ', 'notes' => 'หมายเหตุ', 'remark' => 'หมายเหตุ', 'remarks' => 'หมายเหตุ', 'comment' => 'หมายเหตุ',
        'detail' => 'รายละเอียด', 'details' => 'รายละเอียด', 'description' => 'คำอธิบาย', 'reason' => 'เหตุผล',
        'seq' => 'ลำดับที่', 'sort_order' => 'ลำดับการแสดงผล', 'display_order' => 'ลำดับการแสดงผล', 'ordering' => 'ลำดับการแสดงผล',
        'is_active' => 'สถานะการใช้งาน', 'active' => 'สถานะการใช้งาน', 'enabled' => 'เปิดใช้งานหรือไม่', 'is_deleted' => 'ถูกลบแล้วหรือไม่',
        'created_at' => 'วันเวลาที่สร้างรายการ', 'create_date' => 'วันที่สร้างรายการ', 'created_date' => 'วันที่สร้างรายการ',
        'create_datetime' => 'วันเวลาที่สร้างรายการ', 'created_by' => 'ผู้สร้างรายการ', 'create_by' => 'ผู้สร้างรายการ', 'create_user' => 'ผู้สร้างรายการ',
        'updated_at' => 'วันเวลาที่แก้ไขล่าสุด', 'update_date' => 'วันที่แก้ไขล่าสุด', 'updated_date' => 'วันที่แก้ไขล่าสุด',
        'update_datetime' => 'วันเวลาที่แก้ไขล่าสุด', 'last_update' => 'วันเวลาที่แก้ไขล่าสุด', 'lastupdate' => 'วันเวลาที่แก้ไขล่าสุด',
        'updated_by' => 'ผู้แก้ไขล่าสุด', 'update_by' => 'ผู้แก้ไขล่าสุด', 'update_user' => 'ผู้แก้ไขล่าสุด',
        'deleted_at' => 'วันเวลาที่ลบ', 'entry_datetime' => 'วันเวลาที่บันทึก', 'entry_date' => 'วันที่บันทึก', 'entry_time' => 'เวลาที่บันทึก',
        'username' => 'ชื่อผู้ใช้', 'loginname' => 'ชื่อเข้าใช้งาน', 'password' => 'รหัสผ่าน', 'passweb' => 'รหัสผ่าน', 'user_id' => 'รหัสผู้ใช้',
        'email' => 'อีเมล', 'phone' => 'เบอร์โทรศัพท์', 'tel' => 'เบอร์โทรศัพท์', 'mobile' => 'เบอร์โทรศัพท์มือถือ', 'fax' => 'เบอร์โทรสาร',
        'address' => 'ที่อยู่', 'zipcode' => 'รหัสไปรษณีย์', 'postcode' => 'รหัสไปรษณีย์', 'province' => 'จังหวัด', 'amphur' => 'อำเภอ',
        'amphoe' => 'อำเภอ', 'district' => 'อำเภอ', 'tambon' => 'ตำบล', 'tumbon' => 'ตำบล', 'moo' => 'หมู่ที่', 'road' => 'ถนน', 'soi' => 'ซอย',
        'latitude' => 'ละติจูด', 'longitude' => 'ลองจิจูด', 'lat' => 'ละติจูด', 'lng' => 'ลองจิจูด', 'lon' => 'ลองจิจูด',
        'qty' => 'จำนวน', 'quantity' => 'จำนวน', 'amount' => 'จำนวนเงิน', 'price' => 'ราคา', 'unitprice' => 'ราคาต่อหน่วย', 'unit_price' => 'ราคาต่อหน่วย',
        'cost' => 'ราคาทุน', 'unitcost' => 'ราคาทุนต่อหน่วย', 'discount' => 'ส่วนลด', 'total' => 'ยอดรวม', 'sum_price' => 'ราคารวม', 'unit' => 'หน่วยนับ', 'units' => 'หน่วยนับ',
        'ip' => 'หมายเลข IP', 'ip_address' => 'หมายเลข IP', 'computer' => 'ชื่อเครื่องคอมพิวเตอร์', 'computer_name' => 'ชื่อเครื่องคอมพิวเตอร์',
        'guid' => 'รหัส GUID ของแถว', 'hos_guid' => 'รหัส GUID ของแถว', 'version' => 'รุ่น/เวอร์ชัน', 'image' => 'รูปภาพ', 'picture' => 'รูปภาพ',
        'start_date' => 'วันที่เริ่ม', 'end_date' => 'วันที่สิ้นสุด', 'begin_date' => 'วันที่เริ่ม', 'finish_date' => 'วันที่เสร็จสิ้น',
        'start_time' => 'เวลาเริ่ม', 'end_time' => 'เวลาสิ้นสุด', 'expire_date' => 'วันหมดอายุ', 'year' => 'ปี', 'month' => 'เดือน',
        'no' => 'เลขที่', 'number' => 'เลขที่', 'date' => 'วันที่', 'time' => 'เวลา', 'level' => 'ระดับ', 'value' => 'ค่า', 'result' => 'ผล',
        'flag' => 'ตัวบ่งชี้', 'priority' => 'ระดับความสำคัญ', 'color' => 'สี', 'icon' => 'ไอคอน', 'url' => 'ที่อยู่เว็บ (URL)', 'lang' => 'ภาษา',
        'budget_year' => 'ปีงบประมาณ', 'fiscal_year' => 'ปีงบประมาณ', 'age_year' => 'อายุ (ปี)', 'age_month' => 'อายุ (เดือน)', 'age_day' => 'อายุ (วัน)',
        'ref_no' => 'เลขที่อ้างอิง', 'doc_no' => 'เลขที่เอกสาร', 'bill_no' => 'เลขที่ใบเสร็จ', 'receipt_no' => 'เลขที่ใบเสร็จ', 'serial_no' => 'หมายเลขเครื่อง',
        'file_name' => 'ชื่อไฟล์', 'filename' => 'ชื่อไฟล์', 'file_path' => 'ที่อยู่ไฟล์บนเซิร์ฟเวอร์', 'file_size' => 'ขนาดไฟล์ (ไบต์)', 'mime_type' => 'ชนิดไฟล์ (MIME)',
        'user_agent' => 'เบราว์เซอร์/อุปกรณ์ที่ใช้', 'session_id' => 'รหัส session', 'last_login' => 'วันเวลาที่เข้าระบบล่าสุด', 'login_time' => 'เวลาที่เข้าระบบ', 'logout_time' => 'เวลาที่ออกจากระบบ',
        'tel2' => 'เบอร์โทรศัพท์สำรอง', 'addr' => 'ที่อยู่', 'dx' => 'รหัสโรค (ICD-10)', 'weight_kg' => 'น้ำหนัก (กก.)', 'height_cm' => 'ส่วนสูง (ซม.)',
        'drugname' => 'ชื่อยา', 'drug_name' => 'ชื่อยา', 'strength' => 'ความแรงของยา', 'dosageform' => 'รูปแบบยา', 'generic_name' => 'ชื่อสามัญทางยา', 'trade_name' => 'ชื่อการค้า',
        'did' => 'รหัสยา 24 หลัก', 'tmt_tp_code' => 'รหัสยามาตรฐาน TMT', 'lot_no' => 'เลขที่ผลิต (Lot)', 'hosxp_guid' => 'รหัส GUID ของแถว',
        'vstts' => 'สถานะการมารับบริการ', 'admdoctor' => 'รหัสแพทย์ผู้รับเข้ารักษา', 'dchdoctor' => 'รหัสแพทย์ผู้จำหน่าย', 'incharge_doctor' => 'รหัสแพทย์เจ้าของไข้',
        'rcpno' => 'เลขที่ใบเสร็จ', 'rcpt_money' => 'จำนวนเงินที่รับชำระ', 'uc_money' => 'จำนวนเงินตามสิทธิ', 'paid_money' => 'จำนวนเงินที่ต้องชำระเอง',
        'item_money' => 'ค่าใช้จ่ายรวมของรายการ', 'debt_id' => 'รหัสลูกหนี้', 'finance_number' => 'เลขที่การเงิน', 'oapp_id' => 'รหัสใบนัด',
        'refer_hospcode' => 'รหัสสถานพยาบาลที่ส่งต่อ', 'refer_date' => 'วันที่ส่งต่อ', 'refer_number' => 'เลขที่ใบส่งต่อ', 'referin_number' => 'เลขที่ใบรับส่งต่อ',
        'death_date' => 'วันที่เสียชีวิต', 'deathday' => 'วันที่เสียชีวิต', 'discharge_date' => 'วันที่จำหน่าย', 'admit_date' => 'วันที่รับเข้ารักษา',
        'los' => 'จำนวนวันนอน', 'los_days' => 'จำนวนวันนอน', 'adjrw' => 'ค่าน้ำหนักสัมพัทธ์ที่ปรับแล้ว (AdjRW)', 'rw' => 'ค่าน้ำหนักสัมพัทธ์ (RW)', 'drg' => 'รหัสกลุ่มวินิจฉัยโรคร่วม (DRG)',
        // ---- บุคคล
        'cid' => 'เลขประจำตัวประชาชน 13 หลัก', 'pid' => 'รหัสบุคคล', 'person_id' => 'รหัสบุคคล', 'passport_no' => 'เลขหนังสือเดินทาง',
        'pname' => 'คำนำหน้าชื่อ', 'prefix' => 'คำนำหน้าชื่อ', 'fname' => 'ชื่อ', 'lname' => 'นามสกุล', 'first_name' => 'ชื่อ', 'last_name' => 'นามสกุล',
        'firstname' => 'ชื่อ', 'lastname' => 'นามสกุล', 'fullname' => 'ชื่อ-นามสกุล', 'nickname' => 'ชื่อเล่น',
        'sex' => 'เพศ', 'gender' => 'เพศ', 'birthday' => 'วันเกิด', 'birthdate' => 'วันเกิด', 'birth_date' => 'วันเกิด', 'birth' => 'วันเกิด', 'dob' => 'วันเกิด',
        'age' => 'อายุ', 'age_y' => 'อายุ (ปี)', 'age_m' => 'อายุ (เดือน)', 'age_d' => 'อายุ (วัน)',
        'nationality' => 'สัญชาติ', 'citizenship' => 'เชื้อชาติ', 'religion' => 'ศาสนา', 'occupation' => 'อาชีพ', 'education' => 'ระดับการศึกษา',
        'marrystatus' => 'สถานภาพสมรส', 'bloodgrp' => 'หมู่เลือด', 'bloodgroup' => 'หมู่เลือด', 'hometel' => 'เบอร์โทรศัพท์บ้าน',
        'addrpart' => 'บ้านเลขที่', 'moopart' => 'หมู่ที่', 'tmbpart' => 'รหัสตำบล', 'amppart' => 'รหัสอำเภอ', 'chwpart' => 'รหัสจังหวัด',
        'fathername' => 'ชื่อบิดา', 'mathername' => 'ชื่อมารดา', 'spsname' => 'ชื่อคู่สมรส', 'informname' => 'ชื่อผู้ติดต่อ/ญาติ', 'informtel' => 'เบอร์โทรผู้ติดต่อ/ญาติ',
        // ---- ระบบโรงพยาบาล
        'hn' => 'เลขประจำตัวผู้ป่วย (HN)', 'vn' => 'เลขที่การมารับบริการ (VN)', 'an' => 'เลขที่ผู้ป่วยใน (AN)',
        'hcode' => 'รหัสสถานพยาบาล', 'hospcode' => 'รหัสสถานพยาบาล', 'hospmain' => 'รหัสสถานพยาบาลหลักตามสิทธิ', 'hospsub' => 'รหัสสถานพยาบาลรองตามสิทธิ',
        'pttype' => 'รหัสสิทธิการรักษา', 'pttypeno' => 'เลขที่บัตรสิทธิการรักษา', 'pcode' => 'รหัสกลุ่มสิทธิการรักษา',
        'vstdate' => 'วันที่มารับบริการ', 'vsttime' => 'เวลาที่มารับบริการ', 'regdate' => 'วันที่รับเข้ารักษา', 'regtime' => 'เวลาที่รับเข้ารักษา',
        'dchdate' => 'วันที่จำหน่าย', 'dchtime' => 'เวลาที่จำหน่าย', 'dchstts' => 'สถานภาพการจำหน่าย', 'dchtype' => 'ประเภทการจำหน่าย',
        'admdate' => 'จำนวนวันนอน', 'nextdate' => 'วันนัดครั้งถัดไป', 'nexttime' => 'เวลานัดครั้งถัดไป',
        'rxdate' => 'วันที่สั่ง', 'rxtime' => 'เวลาที่สั่ง', 'order_no' => 'เลขที่ใบสั่ง', 'orderdate' => 'วันที่สั่ง', 'order_date' => 'วันที่สั่ง',
        'icode' => 'รหัสรายการยา/เวชภัณฑ์/ค่าบริการ', 'drugusage' => 'รหัสวิธีใช้ยา', 'income' => 'รหัสหมวดค่ารักษาพยาบาล', 'paidst' => 'สถานะการชำระเงิน',
        'icd10' => 'รหัสโรค ICD-10', 'icd9' => 'รหัสหัตถการ ICD-9-CM', 'icd10tm' => 'รหัสโรค ICD-10-TM', 'pdx' => 'รหัสโรคหลัก (ICD-10)',
        'diagtype' => 'ประเภทการวินิจฉัย', 'diag_text' => 'ข้อความการวินิจฉัย', 'cc' => 'อาการสำคัญ (Chief complaint)', 'hpi' => 'ประวัติการเจ็บป่วยปัจจุบัน', 'pmh' => 'ประวัติการเจ็บป่วยในอดีต',
        'doctor' => 'รหัสแพทย์', 'doctor_code' => 'รหัสแพทย์', 'staff' => 'รหัสเจ้าหน้าที่ผู้บันทึก', 'provider' => 'รหัสผู้ให้บริการ', 'licenseno' => 'เลขใบประกอบวิชาชีพ',
        'ward' => 'รหัสหอผู้ป่วย', 'spclty' => 'รหัสแผนก', 'depcode' => 'รหัสห้อง/จุดบริการ', 'main_dep' => 'รหัสห้องตรวจหลัก', 'cur_dep' => 'รหัสจุดบริการปัจจุบัน',
        'clinic' => 'รหัสคลินิก', 'roomno' => 'เลขห้อง', 'bedno' => 'เลขเตียง', 'oqueue' => 'ลำดับคิว', 'ovstist' => 'ประเภทการมารับบริการ', 'ovstost' => 'สถานะเมื่อออกจากห้องตรวจ',
        'bw' => 'น้ำหนัก (กก.)', 'weight' => 'น้ำหนัก', 'height' => 'ส่วนสูง (ซม.)', 'bmi' => 'ดัชนีมวลกาย (BMI)', 'waist' => 'รอบเอว (ซม.)',
        'bps' => 'ความดันโลหิตตัวบน (mmHg)', 'bpd' => 'ความดันโลหิตตัวล่าง (mmHg)', 'pulse' => 'ชีพจร (ครั้ง/นาที)', 'hr' => 'อัตราการเต้นของหัวใจ (ครั้ง/นาที)',
        'rr' => 'อัตราการหายใจ (ครั้ง/นาที)', 'temperature' => 'อุณหภูมิร่างกาย (°C)', 'spo2' => 'ความอิ่มตัวของออกซิเจนในเลือด (%)', 'fbs' => 'ระดับน้ำตาลในเลือดหลังอดอาหาร',
        'drugallergy' => 'ประวัติแพ้ยา', 'lab_order_number' => 'เลขที่ใบสั่ง Lab', 'lab_items_code' => 'รหัสรายการ Lab', 'lab_order_result' => 'ผล Lab',
        'xn' => 'เลขที่การตรวจรังสี (XN)', 'village_id' => 'รหัสหมู่บ้าน', 'house_id' => 'รหัสบ้าน', 'd_update' => 'วันเวลาที่ปรับปรุงข้อมูล',
    ];

    /** คำที่ใช้แปลส่วนของชื่อคอลัมน์ในกฎรูปแบบชื่อ */
    const WORDS = [
        'create' => 'สร้าง', 'created' => 'สร้าง', 'update' => 'แก้ไข', 'updated' => 'แก้ไข', 'modify' => 'แก้ไข', 'modified' => 'แก้ไข',
        'delete' => 'ลบ', 'deleted' => 'ลบ', 'cancel' => 'ยกเลิก', 'cancelled' => 'ยกเลิก', 'canceled' => 'ยกเลิก', 'confirm' => 'ยืนยัน', 'confirmed' => 'ยืนยัน',
        'approve' => 'อนุมัติ', 'approved' => 'อนุมัติ', 'send' => 'ส่ง', 'sent' => 'ส่ง', 'receive' => 'รับ', 'received' => 'รับ', 'print' => 'พิมพ์', 'printed' => 'พิมพ์',
        'start' => 'เริ่ม', 'begin' => 'เริ่ม', 'end' => 'สิ้นสุด', 'finish' => 'เสร็จสิ้น', 'expire' => 'หมดอายุ', 'expired' => 'หมดอายุ', 'due' => 'ครบกำหนด',
        'admit' => 'รับเข้ารักษา', 'discharge' => 'จำหน่าย', 'visit' => 'การมารับบริการ', 'refer' => 'ส่งต่อ', 'appointment' => 'นัดหมาย', 'appoint' => 'นัดหมาย',
        'register' => 'ลงทะเบียน', 'registered' => 'ลงทะเบียน', 'login' => 'เข้าระบบ', 'logout' => 'ออกจากระบบ', 'entry' => 'บันทึก', 'record' => 'บันทึก',
        'report' => 'รายงาน', 'order' => 'สั่ง', 'pay' => 'ชำระเงิน', 'paid' => 'ชำระเงิน', 'payment' => 'ชำระเงิน', 'return' => 'คืน', 'borrow' => 'ยืม',
        'check' => 'ตรวจสอบ', 'checked' => 'ตรวจสอบ', 'service' => 'ให้บริการ', 'screen' => 'คัดกรอง', 'exam' => 'ตรวจ', 'assess' => 'ประเมิน', 'assessed' => 'ประเมิน',
        'birth' => 'เกิด', 'death' => 'เสียชีวิต', 'dead' => 'เสียชีวิต', 'last' => 'ล่าสุด', 'first' => 'ครั้งแรก', 'next' => 'ครั้งถัดไป', 'prev' => 'ครั้งก่อน',
        'patient' => 'ผู้ป่วย', 'person' => 'บุคคล', 'doctor' => 'แพทย์', 'nurse' => 'พยาบาล', 'staff' => 'เจ้าหน้าที่', 'user' => 'ผู้ใช้', 'officer' => 'เจ้าหน้าที่',
        'department' => 'แผนก', 'dept' => 'แผนก', 'dep' => 'แผนก', 'ward' => 'หอผู้ป่วย', 'room' => 'ห้อง', 'bed' => 'เตียง', 'clinic' => 'คลินิก', 'hospital' => 'โรงพยาบาล', 'hosp' => 'โรงพยาบาล',
        'drug' => 'ยา', 'lab' => 'Lab', 'xray' => 'รังสี', 'item' => 'รายการ', 'items' => 'รายการ', 'group' => 'กลุ่ม', 'type' => 'ประเภท', 'status' => 'สถานะ', 'role' => 'บทบาท',
        'province' => 'จังหวัด', 'amphur' => 'อำเภอ', 'district' => 'อำเภอ', 'tambon' => 'ตำบล', 'village' => 'หมู่บ้าน', 'house' => 'บ้าน',
        'company' => 'บริษัท', 'vendor' => 'ผู้ขาย', 'supplier' => 'ผู้ขาย', 'customer' => 'ลูกค้า', 'stock' => 'คลัง', 'bill' => 'ใบเสร็จ', 'invoice' => 'ใบแจ้งหนี้',
        'diag' => 'การวินิจฉัย', 'diagnosis' => 'การวินิจฉัย', 'operation' => 'หัตถการ', 'oper' => 'หัตถการ', 'vaccine' => 'วัคซีน', 'screening' => 'คัดกรอง',
        'parent' => 'รายการแม่', 'owner' => 'เจ้าของ', 'sender' => 'ผู้ส่ง', 'receiver' => 'ผู้รับ', 'request' => 'ขอ', 'response' => 'ตอบกลับ', 'result' => 'ผล',
        'file' => 'ไฟล์', 'image' => 'รูปภาพ', 'doc' => 'เอกสาร', 'document' => 'เอกสาร', 'form' => 'แบบฟอร์ม', 'template' => 'แม่แบบ', 'menu' => 'เมนู', 'module' => 'โมดูล',
        'old' => 'เดิม', 'new' => 'ใหม่', 'current' => 'ปัจจุบัน', 'cur' => 'ปัจจุบัน', 'main' => 'หลัก', 'sub' => 'ย่อย', 'default' => 'เริ่มต้น', 'other' => 'อื่น ๆ',
        'max' => 'สูงสุด', 'min' => 'ต่ำสุด', 'avg' => 'เฉลี่ย', 'total' => 'รวม', 'sum' => 'รวม', 'net' => 'สุทธิ', 'actual' => 'จริง', 'plan' => 'ตามแผน', 'target' => 'เป้าหมาย',
        'name' => 'ชื่อ', 'code' => 'รหัส', 'date' => 'วันที่', 'time' => 'เวลา', 'note' => 'หมายเหตุ', 'price' => 'ราคา', 'cost' => 'ราคาทุน', 'qty' => 'จำนวน',
        'transfer' => 'ย้าย', 'transferred' => 'ย้าย', 'move' => 'ย้าย', 'lock' => 'ล็อก', 'locked' => 'ล็อก', 'close' => 'ปิด', 'closed' => 'ปิด', 'open' => 'เปิด', 'opened' => 'เปิด',
        'accept' => 'รับ', 'accepted' => 'รับ', 'reject' => 'ปฏิเสธ', 'rejected' => 'ปฏิเสธ', 'verify' => 'ตรวจสอบ', 'verified' => 'ตรวจสอบ', 'review' => 'ทบทวน', 'reviewed' => 'ทบทวน',
        'complete' => 'เสร็จสิ้น', 'completed' => 'เสร็จสิ้น', 'process' => 'ดำเนินการ', 'processed' => 'ดำเนินการ', 'sync' => 'ซิงค์', 'synced' => 'ซิงค์', 'import' => 'นำเข้า', 'export' => 'ส่งออก',
        'upload' => 'อัปโหลด', 'uploaded' => 'อัปโหลด', 'download' => 'ดาวน์โหลด', 'view' => 'เปิดดู', 'viewed' => 'เปิดดู', 'read' => 'อ่าน', 'notify' => 'แจ้งเตือน', 'notified' => 'แจ้งเตือน',
        'assign' => 'มอบหมาย', 'assigned' => 'มอบหมาย', 'schedule' => 'นัดหมาย', 'scheduled' => 'นัดหมาย', 'reserve' => 'จอง', 'reserved' => 'จอง', 'allocated' => 'จัดสรร', 'fulfilled' => 'ส่งมอบ',
        'dispense' => 'จ่ายยา', 'dispensed' => 'จ่ายยา', 'prescribe' => 'สั่งยา', 'injection' => 'ฉีดยา', 'treatment' => 'การรักษา', 'consult' => 'ปรึกษา', 'followup' => 'ติดตาม', 'follow' => 'ติดตาม',
        'caregiver' => 'ผู้ดูแล', 'relative' => 'ญาติ', 'family' => 'ครอบครัว', 'contact' => 'ผู้ติดต่อ', 'visitor' => 'ผู้เยี่ยม', 'physician' => 'แพทย์', 'pharmacist' => 'เภสัชกร',
        'org' => 'หน่วยงาน', 'organization' => 'หน่วยงาน', 'unit' => 'หน่วย', 'team' => 'ทีม', 'site' => 'หน่วยบริการ', 'position' => 'ตำแหน่ง', 'program' => 'โปรแกรม', 'project' => 'โครงการ',
        'equipment' => 'อุปกรณ์', 'device' => 'อุปกรณ์', 'asset' => 'ครุภัณฑ์', 'material' => 'วัสดุ', 'supply' => 'เวชภัณฑ์', 'medication' => 'ยา', 'allergy' => 'การแพ้',
        'disease' => 'โรค', 'symptom' => 'อาการ', 'pain' => 'อาการปวด', 'wound' => 'แผล', 'surgery' => 'การผ่าตัด', 'pregnancy' => 'การตั้งครรภ์', 'delivery' => 'การคลอด', 'labor' => 'การคลอด',
        'insurance' => 'ประกัน', 'right' => 'สิทธิ', 'rights' => 'สิทธิ', 'claim' => 'การเบิกจ่าย', 'income' => 'รายรับ', 'expense' => 'รายจ่าย', 'debt' => 'หนี้', 'receipt' => 'ใบเสร็จ',
        'queue' => 'คิว', 'log' => 'ประวัติ', 'history' => 'ประวัติ', 'setting' => 'การตั้งค่า', 'config' => 'การตั้งค่า', 'permission' => 'สิทธิ์การใช้งาน', 'session' => 'session',
        'category' => 'หมวด', 'class' => 'ชั้น', 'level' => 'ระดับ', 'score' => 'คะแนน', 'grade' => 'ระดับ', 'problem' => 'ปัญหา', 'goal' => 'เป้าหมาย', 'outcome' => 'ผลลัพธ์',
        'attachment' => 'ไฟล์แนบ', 'message' => 'ข้อความ', 'comment' => 'ความเห็น', 'reason' => 'เหตุผล', 'home' => 'บ้าน', 'address' => 'ที่อยู่', 'location' => 'ตำแหน่ง', 'area' => 'พื้นที่',
        'enrollment' => 'การลงทะเบียน', 'assessment' => 'การประเมิน', 'reservation' => 'การจอง', 'loan' => 'การยืม', 'notification' => 'การแจ้งเตือน', 'summary' => 'สรุป',
    ];

    /** คำศัพท์เพิ่มเติมสำหรับกฎรูปแบบชื่อ (รวมกับ WORDS ตอนใช้งาน) */
    const WORDS2 = [
        'photo' => 'รูปภาพ', 'picture' => 'รูปภาพ', 'pic' => 'รูปภาพ', 'img' => 'รูปภาพ', 'avatar' => 'รูปประจำตัว', 'logo' => 'โลโก้', 'icon' => 'ไอคอน',
        'video' => 'วิดีโอ', 'audio' => 'เสียง', 'signature' => 'ลายเซ็น', 'sign' => 'ลายเซ็น', 'barcode' => 'บาร์โค้ด', 'qrcode' => 'คิวอาร์โค้ด', 'qr' => 'คิวอาร์โค้ด',
        'account' => 'บัญชี', 'bank' => 'ธนาคาร', 'branch' => 'สาขา', 'budget' => 'งบประมาณ', 'fund' => 'กองทุน', 'tax' => 'ภาษี', 'vat' => 'ภาษีมูลค่าเพิ่ม', 'fee' => 'ค่าธรรมเนียม',
        'salary' => 'เงินเดือน', 'money' => 'เงิน', 'cash' => 'เงินสด', 'credit' => 'เครดิต', 'debit' => 'เดบิต', 'balance' => 'ยอดคงเหลือ', 'deposit' => 'เงินมัดจำ', 'refund' => 'คืนเงิน',
        'product' => 'สินค้า', 'goods' => 'สินค้า', 'purchase' => 'จัดซื้อ', 'sale' => 'ขาย', 'sales' => 'ขาย', 'warehouse' => 'คลังสินค้า', 'store' => 'คลัง', 'inventory' => 'พัสดุคงคลัง',
        'lot' => 'ล็อตการผลิต', 'batch' => 'ชุด', 'package' => 'แพ็กเกจ', 'pack' => 'บรรจุภัณฑ์', 'brand' => 'ยี่ห้อ', 'model' => 'รุ่น', 'size' => 'ขนาด', 'color' => 'สี', 'weight' => 'น้ำหนัก',
        'employee' => 'พนักงาน', 'emp' => 'พนักงาน', 'manager' => 'ผู้จัดการ', 'head' => 'หัวหน้า', 'admin' => 'ผู้ดูแลระบบ', 'member' => 'สมาชิก', 'student' => 'นักเรียน', 'teacher' => 'ครู',
        'father' => 'บิดา', 'mother' => 'มารดา', 'spouse' => 'คู่สมรส', 'child' => 'บุตร', 'baby' => 'ทารก', 'newborn' => 'ทารกแรกเกิด', 'elderly' => 'ผู้สูงอายุ', 'volunteer' => 'อาสาสมัคร',
        'nation' => 'สัญชาติ', 'nationality' => 'สัญชาติ', 'religion' => 'ศาสนา', 'occupation' => 'อาชีพ', 'education' => 'การศึกษา', 'marry' => 'สมรส', 'blood' => 'เลือด', 'sex' => 'เพศ', 'gender' => 'เพศ',
        'country' => 'ประเทศ', 'region' => 'ภาค', 'zone' => 'เขต', 'city' => 'เมือง', 'moo' => 'หมู่', 'road' => 'ถนน', 'building' => 'อาคาร', 'floor' => 'ชั้น', 'zip' => 'รหัสไปรษณีย์',
        'opd' => 'ผู้ป่วยนอก', 'ipd' => 'ผู้ป่วยใน', 'er' => 'ห้องฉุกเฉิน', 'icu' => 'หอผู้ป่วยวิกฤต', 'or' => 'ห้องผ่าตัด', 'lr' => 'ห้องคลอด', 'anc' => 'ฝากครรภ์', 'pp' => 'ส่งเสริมป้องกัน',
        'dental' => 'ทันตกรรม', 'dent' => 'ทันตกรรม', 'pharmacy' => 'ห้องยา', 'rx' => 'ใบสั่งยา', 'prescription' => 'ใบสั่งยา', 'dose' => 'ขนาดยา', 'usage' => 'วิธีใช้', 'frequency' => 'ความถี่',
        'specimen' => 'สิ่งส่งตรวจ', 'test' => 'การตรวจ', 'vital' => 'สัญญาณชีพ', 'temp' => 'อุณหภูมิ', 'pulse' => 'ชีพจร', 'bp' => 'ความดันโลหิต', 'height' => 'ส่วนสูง', 'bmi' => 'ดัชนีมวลกาย',
        'icd' => 'รหัสโรค', 'icd10' => 'รหัสโรค ICD-10', 'icd9' => 'รหัสหัตถการ ICD-9', 'dx' => 'การวินิจฉัย', 'procedure' => 'หัตถการ', 'proc' => 'หัตถการ', 'anesthesia' => 'การระงับความรู้สึก',
        'nutrition' => 'โภชนาการ', 'food' => 'อาหาร', 'diet' => 'อาหาร', 'exercise' => 'การออกกำลังกาย', 'smoking' => 'การสูบบุหรี่', 'alcohol' => 'การดื่มสุรา', 'risk' => 'ความเสี่ยง',
        'chronic' => 'โรคเรื้อรัง', 'cancer' => 'มะเร็ง', 'stroke' => 'โรคหลอดเลือดสมอง', 'dm' => 'เบาหวาน', 'ht' => 'ความดันโลหิตสูง', 'tb' => 'วัณโรค', 'ckd' => 'โรคไตเรื้อรัง', 'copd' => 'โรคปอดอุดกั้นเรื้อรัง',
        'palliative' => 'การดูแลแบบประคับประคอง', 'rehab' => 'การฟื้นฟู', 'physio' => 'กายภาพบำบัด', 'pt' => 'กายภาพบำบัด', 'emergency' => 'ฉุกเฉิน', 'accident' => 'อุบัติเหตุ', 'ambulance' => 'รถพยาบาล',
        'car' => 'รถ', 'vehicle' => 'ยานพาหนะ', 'driver' => 'พนักงานขับรถ', 'trip' => 'เที่ยวเดินทาง', 'route' => 'เส้นทาง', 'transport' => 'การขนส่ง', 'distance' => 'ระยะทาง', 'fuel' => 'เชื้อเพลิง',
        'meeting' => 'การประชุม', 'event' => 'เหตุการณ์', 'task' => 'งาน', 'job' => 'งาน', 'work' => 'งาน', 'shift' => 'เวร', 'leave' => 'การลา', 'holiday' => 'วันหยุด', 'calendar' => 'ปฏิทิน',
        'news' => 'ข่าว', 'post' => 'โพสต์', 'topic' => 'หัวข้อ', 'question' => 'คำถาม', 'answer' => 'คำตอบ', 'choice' => 'ตัวเลือก', 'option' => 'ตัวเลือก', 'survey' => 'แบบสำรวจ', 'feedback' => 'ความคิดเห็น',
        'system' => 'ระบบ', 'app' => 'แอป', 'api' => 'API', 'key' => 'คีย์', 'token' => 'token', 'agent' => 'ตัวแทน', 'client' => 'เครื่องลูกข่าย', 'server' => 'เครื่องแม่ข่าย', 'host' => 'เครื่องแม่ข่าย',
        'error' => 'ข้อผิดพลาด', 'audit' => 'การตรวจสอบย้อนหลัง', 'backup' => 'สำรองข้อมูล', 'version' => 'เวอร์ชัน', 'source' => 'แหล่งที่มา', 'origin' => 'ต้นทาง', 'destination' => 'ปลายทาง', 'dest' => 'ปลายทาง',
        'period' => 'ช่วงเวลา', 'year' => 'ปี', 'month' => 'เดือน', 'week' => 'สัปดาห์', 'day' => 'วัน', 'hour' => 'ชั่วโมง', 'minute' => 'นาที', 'age' => 'อายุ', 'duration' => 'ระยะเวลา',
        'issue' => 'ออก', 'issued' => 'ออก', 'effective' => 'มีผล', 'release' => 'ปล่อย', 'publish' => 'เผยแพร่', 'published' => 'เผยแพร่', 'submit' => 'ส่ง', 'submitted' => 'ส่ง',
        'request' => 'ร้องขอ', 'requested' => 'ร้องขอ', 'respond' => 'ตอบกลับ', 'responded' => 'ตอบกลับ', 'reply' => 'ตอบกลับ', 'need' => 'ต้องการ', 'needed' => 'ต้องการ', 'use' => 'ใช้', 'used' => 'ใช้',
        'ref' => 'อ้างอิง', 'reference' => 'อ้างอิง', 'link' => 'ลิงก์', 'serial' => 'หมายเลขเครื่อง', 'tag' => 'ป้ายกำกับ', 'kind' => 'ชนิด', 'mode' => 'โหมด', 'state' => 'สถานะ', 'step' => 'ขั้นตอน',
    ];

    /** ตารางที่ชื่อบ่งว่าเก็บรายชื่อบุคคล ไม่ดึงชื่อมาแสดงเป็นคำแปลของรหัส */
    const PERSON_TABLE = '/(patient|person|people|doctor|user|staff|officer|employee|member|customer|nurse|provider|caregiver|relative|contact)/i';

    /** [คำลงท้าย, รูปประโยค] ตรวจตามลำดับ %s = ส่วนที่เหลือของชื่อ */
    const SUFFIX = [
        ['_datetime', 'วันเวลาที่%s'], ['_date_time', 'วันเวลาที่%s'], ['_at', 'วันเวลาที่%s'], ['_date', 'วันที่%s'], ['date', 'วันที่%s'],
        ['_time', 'เวลาที่%s'], ['time', 'เวลาที่%s'], ['_by', 'ผู้%s'], ['_user', 'ผู้%s'], ['_staff', 'เจ้าหน้าที่ผู้%s'],
        ['_id', 'รหัส%s'], ['_code', 'รหัส%s'], ['_no', 'เลขที่%s'], ['_number', 'เลขที่%s'], ['_name', 'ชื่อ%s'], ['_type', 'ประเภท%s'],
        ['_status', 'สถานะ%s'], ['_note', 'หมายเหตุ%s'], ['_remark', 'หมายเหตุ%s'], ['_count', 'จำนวน%s'], ['_qty', 'จำนวน%s'],
        ['_amount', 'จำนวนเงิน%s'], ['_price', 'ราคา%s'], ['_cost', 'ราคาทุน%s'], ['_percent', 'ร้อยละ%s'], ['_flag', 'ตัวบ่งชี้%s'],
    ];

    // ------------------------------------------------------------------
    //  กฎเดาความหมาย
    // ------------------------------------------------------------------

    private static function word(string $w): ?string
    {
        return self::WORDS[$w] ?? self::WORDS2[$w] ?? null;
    }

    /** ต่อคำ: ไทยติดไทยไม่เว้นวรรค มีคำอังกฤษหรือตัวเลขจึงเว้น */
    private static function joinWords(array $words): string
    {
        $out = '';
        foreach ($words as $w) {
            $gap = $out !== '' && (preg_match('/[A-Za-z0-9]$/', $out) || preg_match('/^[A-Za-z0-9]/', $w));
            $out .= ($gap ? ' ' : '') . $w;
        }
        return $out;
    }

    /** แปลส่วนของชื่อคอลัมน์ กลับลำดับคำให้เป็นแบบไทย (refer_hospital = โรงพยาบาลส่งต่อ) */
    private static function words(string $stem): string
    {
        $out = [];
        foreach (preg_split('/[_\s]+/', trim($stem, '_')) as $w) {
            if ($w !== '') {
                $out[] = self::word($w) ?? $w;
            }
        }
        return self::joinWords(array_reverse($out));
    }

    /** หัวเรื่องของตาราง: ท่อนแรกของคำอธิบายตาราง หรือแปลจากชื่อตาราง ใช้ขยายชื่อกว้าง ๆ เช่น name, status */
    public static function subject(string $tableName, ?string $description): ?string
    {
        $d = trim((string)$description);
        if ($d !== '') {
            $d = trim((string)preg_split('/\s+(?=1 แถว|เช่น|พร้อม|ใช้|โดย|สำหรับ|ผูก|ซึ่ง|เพื่อ|เก็บ)|[,(:;]|\s-\s/u', $d)[0]);
            if ($d !== '' && mb_strlen($d) <= 40) {
                return $d;
            }
        }
        $tokens = array_values(array_filter(preg_split('/_+/', strtolower($tableName)), 'strlen'));
        // ต้องแปลคำสุดท้ายของชื่อตารางได้ ไม่เช่นนั้นหัวเรื่องจะคลาดเคลื่อน (visit_problem ไม่ใช่ "การมารับบริการ")
        if (!$tokens || self::word(end($tokens)) === null) {
            return null;
        }
        $w = [];
        foreach ($tokens as $tok) {
            if (self::word($tok) !== null) {
                $w[] = self::word($tok);
            }
        }
        return self::joinWords(array_reverse($w));
    }

    public static function byDict(string $name): ?string
    {
        return self::DICT[strtolower($name)] ?? null;
    }

    public static function byPattern(string $name): ?string
    {
        $n = strtolower($name);
        // ชื่อที่ลงท้ายด้วยตัวเลข เช่น dx1, tel_2 = รายการลำดับที่ N ของชื่อฐาน
        if (preg_match('/^([a-z_]{2,}?)_?(\d{1,2})$/', $n, $m)) {
            $base = self::DICT[$m[1]] ?? self::byPattern($m[1]);
            return $base !== null ? $base . ' ลำดับที่ ' . (int)$m[2] : null;
        }
        if (preg_match('/^(is|has|can|allow|use)_(.+)$/', $n, $m)) {
            $verb = ['is' => '', 'has' => 'มี', 'can' => 'สามารถ', 'allow' => 'อนุญาต', 'use' => 'ใช้'][$m[1]];
            $rest = self::words($m[2]);
            // ต้องแปลได้อย่างน้อยหนึ่งคำ ไม่เช่นนั้นถือว่าเดาไม่ได้
            return preg_match('/[ก-๙]/u', $rest) ? $verb . $rest . 'หรือไม่' : null;
        }
        foreach (self::SUFFIX as $rule) {
            $suf = $rule[0];
            if (strlen($n) > strlen($suf) + 1 && substr($n, -strlen($suf)) === $suf) {
                $stem = self::words(substr($n, 0, -strlen($suf)));
                // ต้องแปลส่วนที่เหลือได้อย่างน้อยหนึ่งคำ ไม่เช่นนั้นถือว่าเดาไม่ได้
                if ($stem !== '' && preg_match('/[ก-๙]/u', $stem)) {
                    return sprintf($rule[1], $stem);
                }
                return null;
            }
        }
        return null;
    }

    /** ค่าที่เป็นไปได้ที่รู้จากโครงสร้างอย่างเดียว (enum และ boolean) */
    public static function valuesFromType(string $name, string $columnType): ?string
    {
        $t = strtolower(trim($columnType));
        if (preg_match('/^(enum|set)\((.*)\)$/s', $t, $m) && preg_match_all("/'((?:[^']|'')*)'/", $columnType, $v)) {
            return implode(', ', array_map(function ($x) {
                return str_replace("''", "'", $x);
            }, $v[1]));
        }
        if (preg_match('/^(tinyint\(1\)|bit\(1\)|bool|boolean)/', $t) || ($t !== '' && preg_match('/^(tinyint|smallint|int|bit)/', $t) && preg_match('/^(is|has|can|allow)_/i', $name))) {
            return '1=ใช่, 0=ไม่ใช่';
        }
        return null;
    }

    /**
     * ความหมายที่คนเขียนไว้แล้วของคอลัมน์แต่ละชื่อ (ไม่นับที่ระบบเดาเอง)
     * คืน [ชื่อตัวพิมพ์เล็ก => [description, จำนวนที่ใช้ความหมายนี้, จำนวนทั้งหมด]]
     * ใช้ได้เมื่อความหมายเดียวกันถูกใช้เกิน 60% ของคอลัมน์ชื่อนั้น
     */
    private static function sameNameMap(int $pid, ?array $names = null, string $field = 'description'): array
    {
        $field = $field === 'allowed_values' ? 'allowed_values' : 'description';
        $sql = "SELECT LOWER(column_name) AS n, $field AS d, COUNT(*) AS c FROM dd_columns
                WHERE project_id = ? AND is_dropped = 0 AND $field IS NOT NULL AND $field <> ''
                  AND (described_by IS NULL OR described_by <> ? OR 'allowed_values' = '$field')";
        $params = [$pid, self::BY];
        if ($names !== null) {
            if (!$names) {
                return [];
            }
            $sql .= ' AND column_name IN (' . implode(',', array_fill(0, count($names), '?')) . ')';
            $params = array_merge($params, $names);
        }
        $best = [];
        $total = [];
        foreach (q($sql . " GROUP BY LOWER(column_name), $field", $params) as $r) {
            $n = (string)$r['n'];
            $c = (int)$r['c'];
            $total[$n] = ($total[$n] ?? 0) + $c;
            if (!isset($best[$n]) || $c > $best[$n][1]) {
                $best[$n] = [trim((string)$r['d']), $c];
            }
        }
        $out = [];
        foreach ($best as $n => $b) {
            if (!in_array($n, self::GENERIC, true) && $b[1] / $total[$n] > 0.6) {
                $out[$n] = [$b[0], $b[1], $total[$n]];
            }
        }
        return $out;
    }

    /** เดาความหมายของชื่อคอลัมน์หนึ่งชื่อ ตามแหล่งที่เปิดใช้ คืน [ความหมาย, แหล่ง] หรือ null */
    private static function guessName(string $name, array $same, array $sources): ?array
    {
        $n = strtolower($name);
        if (in_array('same', $sources, true) && isset($same[$n])) {
            return [$same[$n][0], 'same'];
        }
        if (in_array('dict', $sources, true) && ($d = self::byDict($n)) !== null) {
            return [$d, 'dict'];
        }
        if (in_array('pattern', $sources, true) && ($d = self::byPattern($n)) !== null) {
            return [$d, 'pattern'];
        }
        return null;
    }

    /**
     * เดาความหมายของคอลัมน์หนึ่งคอลัมน์โดยใช้บริบทของตาราง
     * $c = แถวคอลัมน์ (column_name, extra), $t = ตารางเจ้าของ (table_name, description),
     * $refT = ตารางที่อ้างอิงถึง (table_name, description) หรือ null
     */
    private static function guessColumn(array $c, array $t, array $same, ?array $refT, array $sources): ?array
    {
        $n = strtolower((string)$c['column_name']);
        if (in_array('same', $sources, true) && isset($same[$n])) {
            return [$same[$n][0], 'same'];
        }
        if (in_array('dict', $sources, true)) {
            if (isset(self::CTX[$n])) {
                $subj = self::subject((string)$t['table_name'], $t['description'] ?? null);
                $d = $subj !== null ? sprintf(self::CTX[$n], $subj) : (self::DICT[$n] ?? null);
                if ($d !== null) {
                    $auto = stripos((string)($c['extra'] ?? ''), 'auto_increment') !== false;
                    return [$d . ($auto ? ' (เลขรันอัตโนมัติ)' : ''), 'dict'];
                }
            } elseif (isset(self::DICT[$n])) {
                return [self::DICT[$n], 'dict'];
            }
        }
        if (in_array('ref', $sources, true) && $refT !== null) {
            $subj = self::subject((string)$refT['table_name'], $refT['description'] ?? null);
            return [($subj !== null ? 'รหัส' . $subj : 'รหัสอ้างอิง') . ' (อ้างอิงตาราง ' . $refT['table_name'] . ')', 'ref'];
        }
        if (in_array('pattern', $sources, true) && ($d = self::byPattern($n)) !== null) {
            return [$d, 'pattern'];
        }
        return null;
    }

    /**
     * หาตารางรหัสที่คอลัมน์น่าจะอ้างอิงถึง จากชื่อ: ตารางชื่อเดียวกับคอลัมน์ หรือ primary key เดี่ยวชื่อเดียวกันในตารางเดียว
     * คืน [ชื่อคอลัมน์ตัวพิมพ์เล็ก => [ตาราง, คอลัมน์]]
     */
    private static function lookups(int $pid, int $tableId, array $names): array
    {
        if (!$names) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($names), '?'));
        $single = "(SELECT COUNT(*) FROM dd_columns k WHERE k.table_id = c.table_id AND k.column_key = 'PRI' AND k.is_dropped = 0) = 1";
        $out = [];
        $byPk = [];
        foreach (q("SELECT LOWER(c.column_name) AS n, t.table_name, c.column_name FROM dd_columns c JOIN dd_tables t ON t.id = c.table_id
                    WHERE c.project_id = ? AND c.is_dropped = 0 AND t.is_dropped = 0 AND c.column_key = 'PRI' AND c.table_id <> ?
                      AND c.column_name IN ($ph) AND $single", array_merge([$pid, $tableId], $names)) as $r) {
            $byPk[(string)$r['n']][] = [$r['table_name'], $r['column_name']];
        }
        foreach ($byPk as $n => $list) {
            if (count($list) === 1 && !in_array($n, self::GENERIC, true)) {
                $out[$n] = $list[0];
            }
        }
        foreach (q("SELECT LOWER(t.table_name) AS n, t.table_name, c.column_name FROM dd_tables t JOIN dd_columns c ON c.table_id = t.id
                    WHERE t.project_id = ? AND t.is_dropped = 0 AND t.id <> ? AND c.is_dropped = 0 AND c.column_key = 'PRI'
                      AND t.table_name IN ($ph) AND $single", array_merge([$pid, $tableId], $names)) as $r) {
            if (!in_array((string)$r['n'], self::GENERIC, true)) {
                $out[(string)$r['n']] = [$r['table_name'], $r['column_name']];
            }
        }
        return $out;
    }

    private static function tablesByName(int $pid, array $names): array
    {
        $names = array_values(array_unique(array_filter($names, 'strlen')));
        if (!$names) {
            return [];
        }
        $out = [];
        foreach (q('SELECT * FROM dd_tables WHERE project_id = ? AND table_name IN (' . implode(',', array_fill(0, count($names), '?')) . ')',
            array_merge([$pid], $names)) as $r) {
            $out[strtolower((string)$r['table_name'])] = $r;
        }
        return $out;
    }

    private static function cleanSources($sources): array
    {
        $s = array_values(array_intersect(self::SOURCES, is_array($sources) ? $sources : explode(',', (string)$sources)));
        if (!$s) {
            throw new ApiError('เลือกวิธีเดาอย่างน้อย 1 วิธี');
        }
        return $s;
    }

    // ------------------------------------------------------------------
    //  ข้อเสนอสำหรับตารางเดียว (แสดงให้คนกดยอมรับ ไม่บันทึกเอง)
    // ------------------------------------------------------------------

    public static function suggestTable(array $project, array $table): array
    {
        $pid = (int)$project['id'];
        $cols = q('SELECT * FROM dd_columns WHERE table_id = ? AND is_dropped = 0 ORDER BY ordinal_position, id', [$table['id']])->fetchAll();
        $names = array_column($cols, 'column_name');
        $same = self::sameNameMap($pid, $names);
        $sameVals = self::sameNameMap($pid, $names, 'allowed_values');
        $look = self::lookups($pid, (int)$table['id'], $names);
        $refNames = [];
        foreach ($cols as $c) {
            $refNames[] = (string)$c['ref_table'];
        }
        foreach ($look as $l) {
            $refNames[] = $l[0];
        }
        $refTables = self::tablesByName($pid, $refNames);

        $out = [];
        foreach ($cols as $c) {
            $n = strtolower((string)$c['column_name']);
            $s = ['id' => (int)$c['id']];
            $isPk = (string)$c['column_key'] === 'PRI';
            $guessRef = (string)$c['ref_table'] === '' && !$isPk && isset($look[$n]) ? $look[$n] : null;
            $refName = (string)$c['ref_table'] !== '' ? (string)$c['ref_table'] : ($guessRef ? $guessRef[0] : '');
            $refT = $refName !== '' ? ($refTables[strtolower($refName)] ?? null) : null;
            if (trim((string)$c['description']) === '' && ($g = self::guessColumn($c, $table, $same, $refT, self::SOURCES)) !== null) {
                $s['description'] = $g[0];
                $s['source'] = $g[1];
                $s['why'] = self::LABELS[$g[1]] . ($g[1] === 'same' ? ' (' . $same[$n][1] . ' จาก ' . $same[$n][2] . ' คอลัมน์)' : '');
            }
            if (trim((string)$c['allowed_values']) === '') {
                $v = self::valuesFromType($n, (string)$c['column_type']) ?? ($sameVals[$n][0] ?? null);
                if ($v !== null) {
                    $s['allowed_values'] = $v;
                }
            }
            if ($guessRef) {
                $s['ref'] = $guessRef[0] . '.' . $guessRef[1];
            }
            if (count($s) > 1) {
                $out[] = $s;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    //  เดาทั้งโปรเจ็ค: plan() นับอย่างเดียว, apply() บันทึก, undo() ย้อนกลับ
    // ------------------------------------------------------------------

    /**
     * รายการที่จะเติม แต่ละรายการคือ [name, description, source, columns, ids?]
     * มี ids = เติมเฉพาะคอลัมน์เหล่านั้น (ความหมายขึ้นกับตาราง), ไม่มี = เติมทุกคอลัมน์ชื่อนั้นที่ยังว่าง
     */
    private static function projectPlan(int $pid, array $sources): array
    {
        $same = in_array('same', $sources, true) ? self::sameNameMap($pid) : [];
        $useDict = in_array('dict', $sources, true);
        $empty = "c.project_id = ? AND c.is_dropped = 0 AND (c.description IS NULL OR c.description = '')";
        $grouped = [];   // "ชื่อ\0ความหมาย" => รายการที่มี ids
        $taken = [];     // ชื่อ => จำนวนคอลัมน์ที่ถูกเติมแบบรายคอลัมน์ไปแล้ว
        $addRow = function (array $r, array $g) use (&$grouped, &$taken) {
            $n = (string)$r['n'];
            $k = $n . "\0" . $g[0];
            if (!isset($grouped[$k])) {
                $grouped[$k] = ['name' => $n, 'description' => $g[0], 'source' => $g[1], 'columns' => 0, 'ids' => []];
            }
            $grouped[$k]['ids'][] = (int)$r['id'];
            $grouped[$k]['columns']++;
            $taken[$n] = ($taken[$n] ?? 0) + 1;
        };

        // 1) ชื่อกว้าง ๆ ที่ต้องดูหัวเรื่องของตาราง
        if ($useDict) {
            $ctx = array_keys(self::CTX);
            foreach (q("SELECT c.id, LOWER(c.column_name) AS n, c.column_name, c.extra, t.table_name, t.description
                        FROM dd_columns c JOIN dd_tables t ON t.id = c.table_id
                        WHERE $empty AND c.column_name IN (" . implode(',', array_fill(0, count($ctx), '?')) . ")",
                array_merge([$pid], $ctx)) as $r) {
                $g = self::guessColumn($r, $r, [], null, ['dict']);
                if ($g !== null) {
                    $addRow($r, $g);
                }
            }
        }
        // 2) คอลัมน์ที่มีตารางอ้างอิง และชื่อยังไม่มีความหมายจากชื่อเดียวกันหรือพจนานุกรม
        if (in_array('ref', $sources, true)) {
            foreach (q("SELECT c.id, LOWER(c.column_name) AS n, rt.table_name, rt.description
                        FROM dd_columns c JOIN dd_tables rt ON rt.project_id = c.project_id AND rt.table_name = c.ref_table
                        WHERE $empty AND c.ref_table IS NOT NULL AND c.ref_table <> ''", [$pid]) as $r) {
                $n = (string)$r['n'];
                if (isset($same[$n]) || ($useDict && (isset(self::CTX[$n]) || isset(self::DICT[$n])))) {
                    continue;
                }
                $g = self::guessColumn(['column_name' => $n], $r, [], $r, ['ref']);
                if ($g !== null) {
                    $addRow($r, $g);
                }
            }
        }
        // 3) ที่เหลือ ความหมายขึ้นกับชื่อคอลัมน์อย่างเดียว
        $items = array_values($grouped);
        foreach (q("SELECT LOWER(c.column_name) AS n, COUNT(*) AS cnt FROM dd_columns c WHERE $empty GROUP BY LOWER(c.column_name)", [$pid]) as $r) {
            $n = (string)$r['n'];
            if ($useDict && isset(self::CTX[$n])) {
                continue;
            }
            $left = (int)$r['cnt'] - ($taken[$n] ?? 0);
            $g = $left > 0 ? self::guessName($n, $same, $sources) : null;
            if ($g !== null) {
                $items[] = ['name' => $n, 'description' => $g[0], 'source' => $g[1], 'columns' => $left];
            }
        }
        usort($items, function ($a, $b) {
            return $b['columns'] <=> $a['columns'];
        });
        return $items;
    }

    private static function summarize(array $items): array
    {
        $by = array_fill_keys(self::SOURCES, 0);
        $samples = [];
        foreach ($items as $it) {
            $by[$it['source']] += $it['columns'];
            if (count($samples) < 80) {
                unset($it['ids']);
                $samples[] = $it;
            }
        }
        return ['columns' => array_sum($by), 'names' => count($items), 'by_source' => $by, 'samples' => $samples];
    }

    public static function plan(array $project, $sources): array
    {
        $pid = (int)$project['id'];
        $res = self::summarize(self::projectPlan($pid, self::cleanSources($sources)));
        $res['guessed_now'] = (int)q("SELECT COUNT(*) FROM dd_columns WHERE project_id = ? AND is_dropped = 0 AND described_by = ?", [$pid, self::BY])->fetchColumn();
        return $res;
    }

    public static function apply(array $project, $sources, string $actor): array
    {
        @set_time_limit(600);
        $pid = (int)$project['id'];
        $started = now();
        $items = self::projectPlan($pid, self::cleanSources($sources));
        // รายการที่ระบุคอลัมน์ต้องทำก่อน เพราะรายการแบบทั้งชื่อเติมทุกช่องว่างที่เหลือของชื่อนั้น
        usort($items, function ($a, $b) {
            return isset($b['ids']) <=> isset($a['ids']);
        });
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $set = "UPDATE dd_columns SET description = ?, described_by = ?, described_at = ? WHERE project_id = ? AND is_dropped = 0 AND (description IS NULL OR description = '')";
            $byName = $pdo->prepare("$set AND column_name = ?");
            $done = 0;
            foreach ($items as &$it) {
                $n = 0;
                if (isset($it['ids'])) {
                    foreach (array_chunk($it['ids'], 500) as $chunk) {
                        $n += q("$set AND id IN (" . implode(',', array_map('intval', $chunk)) . ')', [$it['description'], self::BY, $started, $pid])->rowCount();
                    }
                } else {
                    $byName->execute([$it['description'], self::BY, $started, $pid, $it['name']]);
                    $n = $byName->rowCount();
                }
                $it['columns'] = $n;
                $done += $n;
            }
            unset($it);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $res = self::summarize($items);
        unset($res['samples']);
        $res['stats'] = Dictionary::stats($pid);
        q("INSERT INTO sync_logs (project_id, kind, actor, status, columns_total, columns_new, message, started_at, finished_at) VALUES (?, 'guess', ?, 'ok', ?, ?, ?, ?, ?)",
            [$pid, $actor, $res['stats']['columns'], $done, 'เดาความหมาย ' . number_format($done) . ' คอลัมน์', $started, now()]);
        return $res;
    }

    /** ล้างความหมายที่ระบบเดาให้และยังไม่มีคนยืนยันหรือแก้ไข */
    public static function undo(array $project, string $actor): array
    {
        $pid = (int)$project['id'];
        $started = now();
        $n = q("UPDATE dd_columns SET description = NULL, described_by = NULL, described_at = NULL WHERE project_id = ? AND described_by = ?", [$pid, self::BY])->rowCount();
        $stats = Dictionary::stats($pid);
        q("INSERT INTO sync_logs (project_id, kind, actor, status, columns_total, message, started_at, finished_at) VALUES (?, 'guess', ?, 'ok', ?, ?, ?, ?)",
            [$pid, $actor, $stats['columns'], 'ล้างความหมายที่ระบบเดา ' . number_format($n) . ' คอลัมน์', $started, now()]);
        return ['cleared' => $n, 'stats' => $stats];
    }

    // ------------------------------------------------------------------
    //  ตีความจากข้อมูลจริง
    //  อ่านแถวล่าสุดของตาราง (ค่าเริ่มต้น 50 แถว) แล้วดูว่าแต่ละคอลัมน์เก็บข้อมูลลักษณะใด
    //  คอลัมน์ที่เป็นรหัสจะตามไปตรวจกับ primary key ของตารางอื่น และดึงชื่อของรหัสมาแสดง
    //  การวิเคราะห์ทำบนเครื่องแม่ข่ายทั้งหมด ค่าดิบส่งกลับไปที่หน้าเว็บเฉพาะคอลัมน์รหัส/สถานะที่มีค่าไม่กี่แบบ
    // ------------------------------------------------------------------

    /** ชื่อคอลัมน์ที่น่าจะเป็นข้อมูลส่วนบุคคลหรือข้อความอิสระ: วิเคราะห์ได้ แต่ไม่ส่งค่าดิบกลับไปแสดง */
    const PRIVATE_NAME = '/(^|_)(cid|hn|vn|an|pid|passport|name|fname|lname|nickname|addr|address|addrpart|road|soi|tel|phone|mobile|fax|email|mail|line|'
        . 'birth|birthday|dob|lat|lng|lon|latitude|longitude|gps|password|passwd|pass|pwd|token|secret|key|hash|salt|guid|uuid|session|'
        . 'note|notes|remark|remarks|comment|detail|details|desc|description|text|memo|message|msg|json|xml|html|image|picture|photo|file|path|url|ip|'
        . 'license|licenseno|account|bank|card)(_|$)|name$|tel$|addr$/i';

    const PREFIXES = ['นาย', 'นาง', 'นางสาว', 'น.ส.', 'ด.ช.', 'ด.ญ.', 'เด็กชาย', 'เด็กหญิง', 'พระ', 'พระภิกษุ', 'สามเณร', 'แม่ชี',
        'mr.', 'mrs.', 'miss', 'ms.', 'mr', 'mrs', 'ms', 'นพ.', 'พญ.', 'ทพ.', 'ทพญ.', 'ภก.', 'ภญ.', 'ดร.'];

    private static function isThaiCid(string $v): bool
    {
        if (!preg_match('/^\d{13}$/', $v)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$v[$i] * (13 - $i);
        }
        return ((11 - $sum % 11) % 10) === (int)$v[12];
    }

    private static function fmtNum(float $x): string
    {
        return rtrim(rtrim(number_format($x, 2, '.', ','), '0'), '.');
    }

    /**
     * ดูค่าที่อ่านมาของคอลัมน์หนึ่ง แล้วบอกว่าเป็นข้อมูลลักษณะใด
     * คืน kind (ชนิดภายใน), desc (ความหมายที่มั่นใจจากข้อมูล), weak (ลักษณะข้อมูลเมื่อไม่รู้ความหมาย),
     *     shape (คำบรรยายสิ่งที่พบ), private (ไม่ส่งค่าดิบกลับ), allowed (คำแปลของค่าที่รู้จัก), keys (ค่าที่ไม่ซ้ำ => จำนวน)
     */
    private static function analyzeValues(array $vals, array $c): array
    {
        $type = strtolower(trim((string)$c['column_type']));
        $n = count($vals);
        $counts = [];
        foreach ($vals as $v) {
            $counts[$v] = ($counts[$v] ?? 0) + 1;
        }
        $keys = array_map('strval', array_keys($counts));
        $d = count($keys);
        $tail = $n ? ' (' . number_format($d) . ' ค่าไม่ซ้ำใน ' . number_format($n) . ' แถวที่มีข้อมูล)' : '';
        $res = function (string $kind, ?string $desc, string $shape, bool $private = false, ?string $weak = null, ?string $allowed = null) use ($counts, $tail) {
            return ['kind' => $kind, 'desc' => $desc, 'weak' => $weak, 'shape' => $shape . $tail, 'private' => $private, 'allowed' => $allowed, 'keys' => $counts];
        };
        if ($n === 0) {
            return $res('empty', null, 'ไม่มีข้อมูลในแถวที่อ่าน');
        }
        $share = function (callable $f) use ($vals, $n) {
            $k = 0;
            foreach ($vals as $v) {
                if ($f($v)) {
                    $k++;
                }
            }
            return $k / $n;
        };
        $all = function (string $re, float $min = 0.9) use ($share) {
            return $share(function ($v) use ($re) {
                return (bool)preg_match($re, $v);
            }) >= $min;
        };
        $upper = array_map('mb_strtoupper', $keys);

        // ---- ชนิดวันที่/เวลา: ความหมายต้องดูจากชื่อ ข้อมูลบอกได้แค่ช่วงปี
        if (preg_match('/^(datetime|timestamp|date|time|year)/', $type, $m)) {
            $label = ['date' => 'วันที่', 'datetime' => 'วันเวลา', 'timestamp' => 'วันเวลา', 'time' => 'เวลา', 'year' => 'ปี'][$m[1]];
            $years = [];
            foreach ($vals as $v) {
                if (preg_match('/^(\d{4})(-|$)/', $v, $y) && (int)$y[1] > 0) {
                    $years[] = (int)$y[1];
                }
            }
            $shape = $label;
            if ($years) {
                $shape .= ' ช่วงปี ' . min($years) . (max($years) !== min($years) ? '–' . max($years) : '') . (max($years) > 2400 ? ' (บันทึกเป็นปี พ.ศ.)' : '');
            }
            return $res('date', null, $shape, false, $label);
        }

        // ---- รูปแบบที่บอกความหมายได้ชัดจากข้อมูล
        if ($share([self::class, 'isThaiCid']) >= 0.8) {
            return $res('cid', 'เลขประจำตัวประชาชน 13 หลัก', 'เลข 13 หลักที่ผ่านการตรวจหลักสุดท้ายของเลขประจำตัวประชาชน', true);
        }
        if ($all('/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i')) {
            return $res('email', 'อีเมล', 'อีเมล', true);
        }
        if ($all('~^https?://~i')) {
            return $res('url', 'ที่อยู่เว็บ (URL)', 'ที่อยู่เว็บ', true);
        }
        if ($all('/^(\d{1,3}\.){3}\d{1,3}$/')) {
            return $res('ip', 'หมายเลข IP', 'หมายเลข IP', true);
        }
        if ($all('/^\{?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}?$/i')) {
            return $res('uuid', 'รหัส GUID/UUID ของแถว', 'รหัส GUID/UUID', true);
        }
        if ($all('/^([0-9a-f]{32}|[0-9a-f]{40}|[0-9a-f]{64})$/i')) {
            return $res('hash', 'ค่า hash ความยาว ' . strlen($vals[0]) . ' ตัวอักษร', 'ค่า hash (เลขฐานสิบหก ' . strlen($vals[0]) . ' ตัว)', true);
        }
        if ($all('~^data:[a-z]+/[a-z0-9.+-]+;base64,~i', 0.8)) {
            return $res('datauri', 'ไฟล์หรือรูปภาพที่เข้ารหัสเป็นข้อความ (data URI)', 'ไฟล์เข้ารหัส base64', true);
        }
        if ($all('/^\s*[\{\[]/', 0.8)) {
            $jsonKeys = [];
            $ok = 0;
            foreach ($vals as $v) {
                $j = json_decode($v, true);
                if (is_array($j)) {
                    $ok++;
                    foreach (array_keys($j) as $k) {
                        if (is_string($k)) {
                            $jsonKeys[$k] = true;
                        }
                    }
                }
            }
            // ค่าที่ยาวเกินจะถูกตัดตอนอ่าน จึงแปลง JSON ไม่ได้ ถือว่าเป็น JSON จากรูปแบบขึ้นต้น
            $k = array_slice(array_keys($jsonKeys), 0, 10);
            return $res('json', 'ข้อมูล JSON' . ($k ? ' (คีย์: ' . implode(', ', $k) . ')' : ''), 'ข้อมูล JSON' . ($ok ? '' : ' (ยาวเกินกว่าจะอ่านคีย์)'), true);
        }
        if ($all('/\.(jpe?g|png|gif|bmp|webp|svg|pdf|docx?|xlsx?|pptx?|csv|txt|zip|rar|mp4|mp3|wav)$/i', 0.8)) {
            $ext = [];
            foreach ($vals as $v) {
                $ext[strtolower(pathinfo($v, PATHINFO_EXTENSION))] = true;
            }
            $isPath = $all('~[/\\\\]~', 0.5);
            return $res('file', ($isPath ? 'ที่อยู่ไฟล์' : 'ชื่อไฟล์') . ' (นามสกุล ' . implode(', ', array_slice(array_keys($ext), 0, 5)) . ')', $isPath ? 'ที่อยู่ไฟล์' : 'ชื่อไฟล์', true);
        }
        if ($all('/^0[689]\d{8}$/') || $all('/^0[689]\d[- ]?\d{3,4}[- ]?\d{3,4}$/')) {
            return $res('phone', 'เบอร์โทรศัพท์มือถือ', 'เบอร์โทรศัพท์มือถือ 10 หลัก', true);
        }
        // เบอร์บ้านต้องมีขีดหรือวรรคคั่น เลข 9 หลักล้วนอาจเป็นรหัสที่เติมศูนย์นำหน้า เช่น HN
        if ($all('/^\(?0\d{1,2}\)?[- ]\d{3}[- ]?\d{3,4}$/')) {
            return $res('phone', 'เบอร์โทรศัพท์', 'เบอร์โทรศัพท์', true);
        }
        if ($all('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/')) {
            return $res('datestr', 'วันที่ (เก็บเป็นข้อความ รูปแบบ ปปปป-ดด-วว)', 'วันที่เก็บเป็นข้อความ', false);
        }
        if ($all('~^\d{1,2}/\d{1,2}/\d{4}$~')) {
            return $res('datestr', 'วันที่ (เก็บเป็นข้อความ รูปแบบ วว/ดด/ปปปป)', 'วันที่เก็บเป็นข้อความ', false);
        }
        if ($share(function ($v) {
            return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m) && $m[1] >= 1900 && $m[1] <= 2700 && $m[2] >= 1 && $m[2] <= 12 && $m[3] >= 1 && $m[3] <= 31;
        }) >= 0.9) {
            return $res('datestr', 'วันที่ (เก็บเป็นข้อความ รูปแบบ ปปปปดดวว)', 'วันที่เก็บเป็นข้อความ 8 หลัก', false);
        }
        if ($all('/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/')) {
            return $res('timestr', 'เวลา (เก็บเป็นข้อความ)', 'เวลาเก็บเป็นข้อความ', false);
        }
        $lowerKeys = array_map('mb_strtolower', $keys);
        if (!array_diff($lowerKeys, self::PREFIXES)) {
            return $res('prefix', 'คำนำหน้าชื่อ', 'คำนำหน้าชื่อ', false);
        }
        $prefixRe = '/^(นาย|นางสาว|นาง|น\.ส\.|ด\.ช\.|ด\.ญ\.|เด็กชาย|เด็กหญิง|mr\.?|mrs\.?|miss|ms\.?)\s*\S+\s+\S+/iu';
        if ($all($prefixRe, 0.7)) {
            return $res('fullname', 'ชื่อ-นามสกุล (รวมคำนำหน้า)', 'ชื่อบุคคลพร้อมคำนำหน้า', true);
        }
        if ($all('/(หมู่|ม\.\s?\d|ต\.|อ\.|จ\.|ตำบล|อำเภอ|จังหวัด|ถนน|ซอย|แขวง|เขต)/u', 0.5)) {
            return $res('address', 'ที่อยู่', 'ข้อความที่อยู่ (มีคำว่า หมู่ ตำบล อำเภอ จังหวัด)', true);
        }
        if ($d <= 3 && (!array_diff($upper, ['M', 'F']) || !array_diff($lowerKeys, ['male', 'female']) || !array_diff($keys, ['ชาย', 'หญิง']))) {
            $map = ['M' => 'ชาย', 'F' => 'หญิง', 'MALE' => 'ชาย', 'FEMALE' => 'หญิง'];
            $al = [];
            foreach ($keys as $k) {
                $al[] = isset($map[mb_strtoupper($k)]) ? $k . '=' . $map[mb_strtoupper($k)] : $k;
            }
            return $res('sex', 'เพศ', 'ค่าเพศ', false, null, implode(', ', $al));
        }
        foreach ([[['Y', 'N'], 'Y', 'N'], [['T', 'F'], 'T', 'F'], [['TRUE', 'FALSE'], 'TRUE', 'FALSE'], [['YES', 'NO'], 'YES', 'NO']] as $yn) {
            if (!array_diff($upper, $yn[0])) {
                $al = [];
                foreach ($keys as $k) {
                    $al[] = $k . '=' . (mb_strtoupper($k) === $yn[1] ? 'ใช่' : 'ไม่ใช่');
                }
                return $res('bool', null, 'ค่าใช่/ไม่ใช่', false, 'ตัวบ่งชี้ ใช่/ไม่ใช่', implode(', ', $al));
            }
        }

        // ---- ตัวเลข
        $numericType = (bool)preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|bit|bool|int2|int4|int8|serial|bigserial|money)/', $type);
        if ($numericType && $all('/^-?\d+(\.\d+)?$/', 1.0)) {
            $nums = array_map('floatval', $vals);
            $min = min($nums);
            $max = max($nums);
            $isInt = $all('/^-?\d+$/', 1.0);
            $range = ' ช่วง ' . self::fmtNum($min) . ($max !== $min ? '–' . self::fmtNum($max) : '');
            if (!array_diff($keys, ['0', '1']) && preg_match('/^(tinyint\(1\)|bit|bool)/', $type)) {
                return $res('bool', null, 'ค่า 0/1', false, 'ตัวบ่งชี้ ใช่/ไม่ใช่', '1=ใช่, 0=ไม่ใช่');
            }
            if ($isInt && $d <= 10 && $min >= 2400 && $max <= 2600) {
                return $res('year', 'ปี พ.ศ.', 'ปี พ.ศ. ' . (int)$min . ($max !== $min ? '–' . (int)$max : ''), false);
            }
            if ($isInt && $d <= 10 && $min >= 1900 && $max <= 2100) {
                return $res('year', 'ปี ค.ศ.', 'ปี ค.ศ. ' . (int)$min . ($max !== $min ? '–' . (int)$max : ''), false);
            }
            if (!$isInt && $share(function ($v) {
                return preg_match('/\.\d{3,}$/', $v) && $v >= 5.5 && $v <= 20.5;
            }) >= 0.9) {
                return $res('lat', 'ละติจูด (พิกัดในประเทศไทย)', 'พิกัดละติจูด', true);
            }
            if (!$isInt && $share(function ($v) {
                return preg_match('/\.\d{3,}$/', $v) && $v >= 97.3 && $v <= 105.7;
            }) >= 0.9) {
                return $res('lng', 'ลองจิจูด (พิกัดในประเทศไทย)', 'พิกัดลองจิจูด', true);
            }
            return $res($isInt ? 'int' : 'num', null, ($isInt ? 'ตัวเลขจำนวนเต็ม' : 'ตัวเลขทศนิยม') . $range, false);
        }

        // ---- ข้อความ
        $lens = array_map('mb_strlen', $vals);
        $minL = min($lens);
        $maxL = max($lens);
        $avg = array_sum($lens) / $n;
        $len = $minL === $maxL ? $minL . ' ตัวอักษร' : $minL . '–' . $maxL . ' ตัวอักษร';
        if ($all('/^\d+$/', 0.95)) {
            $w = $minL === $maxL ? 'รหัสตัวเลข ' . $minL . ' หลัก' : 'รหัสตัวเลข ' . $minL . '–' . $maxL . ' หลัก';
            return $res('digits', null, $w, false, $w);
        }
        if ($maxL <= 12 && $all('/^[\x21-\x7e]+$/', 0.95)) {
            return $res('code', null, 'รหัสตัวอักษร ยาว ' . $len, false, 'รหัส (ยาว ' . $len . ')');
        }
        $thai = $share(function ($v) {
            return (bool)preg_match('/[ก-๙]/u', $v);
        }) >= 0.5 ? 'ภาษาไทย' : '';
        if ($avg > 30 || $maxL > 80) {
            return $res('longtext', null, 'ข้อความอิสระ' . $thai . ' ยาวเฉลี่ย ' . round($avg) . ' ตัวอักษร', true, 'ข้อความอิสระ' . $thai);
        }
        return $res('text', null, 'ข้อความสั้น' . $thai . ' ยาว ' . $len, false, 'ข้อความสั้น' . $thai);
    }

    /** ตารางที่ชื่อเกี่ยวข้องกับชื่อคอลัมน์ เรียงตามความใกล้เคียง คืนรายการ [คะแนน, [ตาราง, pk, ชนิดข้อมูล]] */
    private static function fkCandidates(string $n, array $pkTables): array
    {
        $generic = in_array($n, self::GENERIC, true);
        $stem = $generic ? $n : (string)preg_replace('/_?(id|code|cd|key)$/', '', $n);
        if ($stem === '') {
            $stem = $n;
        }
        $out = [];
        foreach ($pkTables as $t) {
            $tn = strtolower($t[0]);
            $pk = strtolower($t[1]);
            $score = 0;
            if ($tn === $n) {
                $score = $pk === $n ? 100 : 90;
            } elseif ($generic) {
                continue;
            } elseif ($tn === $stem) {
                $score = 85;
            } elseif ($pk === $n) {
                $score = 80;
            } elseif (strlen($stem) >= 3 && substr($tn, -strlen($stem) - 1) === '_' . $stem) {
                $score = 70; // ตารางมีคำนำหน้าของระบบ เช่น coc_status กับ status_id
            } elseif (strlen($stem) >= 3 && (strpos($tn, $stem) === 0 || (strlen($tn) >= 3 && strpos($stem, $tn) === 0))) {
                $score = 60;
            } elseif (strlen($tn) >= 4 && strpos($n, $tn) !== false) {
                $score = 50;
            } elseif (strlen($pk) >= 4 && !in_array($pk, self::GENERIC, true) && strpos($n, $pk) !== false) {
                $score = 45;
            }
            if ($score) {
                $out[] = [$score - min(9, abs(strlen($tn) - strlen($stem))) / 10, $t];
            }
        }
        usort($out, function ($a, $b) {
            return $b[0] <=> $a[0];
        });
        return array_slice($out, 0, 6);
    }

    public static function interpretTable(array $project, array $table): array
    {
        $cfg = config();
        if (array_key_exists('allow_value_sampling', $cfg) && !$cfg['allow_value_sampling']) {
            throw new ApiError('ผู้ดูแลระบบปิดการอ่านข้อมูลจากฐานข้อมูลต้นทางไว้ (allow_value_sampling ใน config/config.php)', 403);
        }
        if ($table['table_type'] === 'VIEW') {
            throw new ApiError('ไม่อ่านข้อมูลจากวิว เพราะอาจใช้เวลานานบนฐานข้อมูลต้นทาง ใช้ "เดาจากชื่อคอลัมน์" แทน');
        }
        if ($table['is_dropped']) {
            throw new ApiError('ตารางนี้ไม่มีในฐานข้อมูลต้นทางแล้ว');
        }
        $pid = (int)$project['id'];
        $limit = max(10, min(5000, (int)($cfg['sample_rows'] ?? 50)));
        $cols = q('SELECT * FROM dd_columns WHERE table_id = ? AND is_dropped = 0 ORDER BY ordinal_position, id', [$table['id']])->fetchAll();
        $pg = $project['conn_driver'] === 'pgsql';
        $qi = function (string $s) use ($pg) {
            return $pg ? '"' . str_replace('"', '""', $s) . '"' : '`' . str_replace('`', '``', $s) . '`';
        };
        $schema = ($project['conn_schema'] ?? '') !== '' ? (string)$project['conn_schema'] : 'public';
        $from = function (string $t) use ($pg, $qi, $schema) {
            return $pg ? $qi($schema) . '.' . $qi($t) : $qi($t);
        };

        // ---- อ่านแถวล่าสุด (เรียงตาม primary key ย้อนหลัง ถ้ามี)
        $sel = [];
        $order = [];
        $binary = [];
        foreach ($cols as $c) {
            $type = strtolower(trim((string)$c['column_type']));
            $ln = strtolower((string)$c['column_name']);
            $col = $qi($c['column_name']);
            if (preg_match('/(blob|binary|bytea|geometry|point|polygon|linestring|raster)/', $type)) {
                $binary[$ln] = true;
                continue;
            }
            if ((string)$c['column_key'] === 'PRI') {
                $order[] = $col . ' DESC';
            }
            $long = preg_match('/^(tinytext|text|mediumtext|longtext|json|jsonb|xml)/', $type)
                || (preg_match('/^(var)?char(acter)?( varying)?\s*(\((\d+)\))?/', $type, $m) && (!isset($m[5]) || (int)$m[5] > 400));
            if ($long) {
                $sel[] = ($pg ? "LEFT(CAST($col AS text), 400)" : "LEFT($col, 400)") . " AS $col";
            } elseif (!$pg && strpos($type, 'bit') === 0) {
                $sel[] = "($col + 0) AS $col";
            } else {
                $sel[] = $col;
            }
        }
        if (!$sel) {
            return ['rows_sampled' => 0, 'limit' => $limit, 'suggestions' => []];
        }
        $pdo = Introspector::connect($project);
        try {
            $pdo->exec($pg ? 'SET statement_timeout = 20000' : 'SET SESSION MAX_EXECUTION_TIME = 20000'); // MySQL 5.7.8+
        } catch (Throwable $ignore) {
            try {
                $pdo->exec('SET SESSION max_statement_time = 20'); // MariaDB
            } catch (Throwable $ignore2) {
            }
        }
        try {
            $rows = $pdo->query('SELECT ' . implode(', ', $sel) . ' FROM ' . $from($table['table_name'])
                . ($order ? ' ORDER BY ' . implode(', ', $order) : '') . " LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new ApiError('อ่านข้อมูลจากตาราง ' . $table['table_name'] . ' ไม่ได้: ' . $e->getMessage()
                . ' (ผู้ใช้ฐานข้อมูลของโปรเจ็คต้องมีสิทธิ์ SELECT บนตารางนี้)');
        }
        $data = [];
        $nulls = [];
        $blanks = [];
        foreach ($rows as $r) {
            foreach ($r as $k => $v) {
                $k = strtolower((string)$k);
                if ($v === null) {
                    $nulls[$k] = ($nulls[$k] ?? 0) + 1;
                    continue;
                }
                $v = is_bool($v) ? ($v ? '1' : '0') : trim((string)mb_scrub((string)$v, 'UTF-8'));
                if ($v === '') {
                    $blanks[$k] = ($blanks[$k] ?? 0) + 1;
                    continue;
                }
                $data[$k][] = $v;
            }
        }
        unset($rows);
        $nRows = 0;
        foreach ($cols as $c) {
            $ln = strtolower((string)$c['column_name']);
            $nRows = max($nRows, count($data[$ln] ?? []) + ($nulls[$ln] ?? 0) + ($blanks[$ln] ?? 0));
        }

        // ---- ดูลักษณะข้อมูลของแต่ละคอลัมน์ แล้วตามคอลัมน์รหัสไปตรวจกับ primary key ของตารางอื่น
        $pkTables = [];
        foreach (q("SELECT t.table_name, MIN(c.column_name) AS pk, MIN(c.data_type) AS dt FROM dd_columns c JOIN dd_tables t ON t.id = c.table_id
                    WHERE c.project_id = ? AND c.is_dropped = 0 AND t.is_dropped = 0 AND t.table_type <> 'VIEW' AND c.column_key = 'PRI' AND t.id <> ?
                    GROUP BY t.id, t.table_name HAVING COUNT(*) = 1", [$pid, $table['id']]) as $r) {
            $pkTables[] = [(string)$r['table_name'], (string)$r['pk'], strtolower((string)$r['dt'])];
        }
        $pkCount = 0;
        foreach ($cols as $c) {
            $pkCount += (string)$c['column_key'] === 'PRI' ? 1 : 0;
        }
        $an = [];
        $fks = [];
        $probes = 0;
        $deadline = microtime(true) + 12;
        foreach ($cols as $c) {
            $ln = strtolower((string)$c['column_name']);
            if (isset($binary[$ln])) {
                $an[$ln] = ['kind' => 'binary', 'desc' => null, 'weak' => null, 'shape' => 'ข้อมูลไบนารี ไม่อ่านค่า', 'private' => true, 'allowed' => null, 'keys' => []];
                continue;
            }
            $a = $an[$ln] = self::analyzeValues($data[$ln] ?? [], $c);
            if (!in_array($a['kind'], ['int', 'digits', 'code'], true)) {
                continue;
            }
            $keys = array_slice(array_map('strval', array_keys($a['keys'])), 0, 20);
            if ((string)$c['ref_table'] !== '') {
                if ((string)$c['ref_column'] !== '') {
                    $fks[$ln] = ['table' => (string)$c['ref_table'], 'column' => (string)$c['ref_column'], 'stored' => true, 'matched' => null, 'checked' => count($keys)];
                }
                continue;
            }
            if ((string)$c['column_key'] === 'PRI' && $pkCount === 1) {
                continue; // รหัสของตารางนี้เอง
            }
            $isNum = !array_filter($keys, function ($k) {
                return !preg_match('/^-?\d+$/', $k);
            });
            $best = null;
            foreach (self::fkCandidates($ln, $pkTables) as $cand) {
                list($score, $t) = $cand;
                $pkNumeric = (bool)preg_match('/int|serial|decimal|numeric|float|double|real/', $t[2]);
                // เลขจำนวนเต็มเล็ก ๆ ตรงกับเลขรันของตารางไหนก็ได้ จึงรับเฉพาะตารางที่ชื่อเกี่ยวข้องชัดเจน
                if (($isNum && $pkNumeric && $score < 70) || (!$isNum && $pkNumeric)) {
                    continue;
                }
                if (++$probes > 120 || microtime(true) > $deadline) {
                    break 2;
                }
                $pk = $qi($t[1]);
                $expr = $pg ? "CAST($pk AS text)" : $pk;
                try {
                    $st = $pdo->prepare("SELECT DISTINCT $expr AS k FROM " . $from($t[0]) . " WHERE $expr IN (" . implode(',', array_fill(0, count($keys), '?')) . ') LIMIT 50');
                    $st->execute($keys);
                    $matched = count($st->fetchAll(PDO::FETCH_COLUMN));
                } catch (Throwable $e) {
                    continue; // ไม่มีสิทธิ์อ่านตารางนั้น หรือชนิดข้อมูลเทียบกันไม่ได้
                }
                $ratio = $matched / count($keys);
                if ($ratio >= 0.8 && ($matched >= 2 || $score >= 80) && (!$best || $ratio * 100 + $score > $best['rank'])) {
                    $best = ['table' => $t[0], 'column' => $t[1], 'stored' => false, 'matched' => $matched, 'checked' => count($keys), 'rank' => $ratio * 100 + $score];
                }
            }
            if ($best) {
                $fks[$ln] = $best;
            }
        }

        // ---- ตามไปอ่านชื่อของรหัสจากตารางที่อ้างอิง
        $names = array_column($cols, 'column_name');
        $same = self::sameNameMap($pid, $names);
        $sameVals = self::sameNameMap($pid, $names, 'allowed_values');
        $look = self::lookups($pid, (int)$table['id'], $names);
        $refNames = array_column($fks, 'table');
        foreach ($cols as $c) {
            $refNames[] = (string)$c['ref_table'];
        }
        $refTables = self::tablesByName($pid, $refNames);
        foreach ($fks as $ln => &$fk) {
            $keys = array_map('strval', array_keys($an[$ln]['keys']));
            $fk['labels'] = count($keys) <= 20 ? self::lookupLabels($project, $pdo, $qi, [$fk['table'], $fk['column']], $keys) : [];
        }
        unset($fk);

        // ---- รวมเป็น "ตัวอย่างข้อมูล" และ "ข้อเสนอรอพิจารณา" ของแต่ละคอลัมน์
        $at = now();
        $out = [];
        foreach ($cols as $c) {
            $ln = strtolower((string)$c['column_name']);
            $a = $an[$ln];
            $fk = $fks[$ln] ?? null;
            $private = $a['private'] || preg_match(self::PRIVATE_NAME, (string)$c['column_name']);
            $unique = in_array((string)$c['column_key'], ['PRI', 'UNI'], true);
            $refName = (string)$c['ref_table'] !== '' ? (string)$c['ref_table'] : ($fk ? $fk['table'] : '');
            $refT = $refName !== '' ? ($refTables[strtolower($refName)] ?? null) : null;
            $labels = $fk['labels'] ?? [];
            $keys = array_map('strval', array_keys($a['keys']));
            $nVals = array_sum($a['keys']);
            $listable = !$private && !$unique && $keys
                && in_array($a['kind'], ['int', 'num', 'digits', 'code', 'text', 'bool', 'sex', 'prefix', 'year'], true);

            // ตัวอย่าง: ค่าที่พบซ้ำกัน (ตั้งแต่ 2 แถวขึ้นไป) เรียงจากที่พบบ่อย ไม่เก็บคอลัมน์ข้อมูลส่วนบุคคลและค่าที่ไม่ซ้ำเลย
            $sample = ['shape' => $a['shape'], 'values' => [], 'distinct' => count($keys), 'rows' => $nRows,
                'nulls' => ($nulls[$ln] ?? 0) + ($blanks[$ln] ?? 0), 'at' => $at];
            if ($listable) {
                arsort($a['keys']);
                foreach ($a['keys'] as $v => $cnt) {
                    $v = (string)$v;
                    if (count($sample['values']) >= 12) {
                        break;
                    }
                    if (($cnt >= 2 || $nVals < 10) && mb_strlen($v) <= 60) {
                        $sample['values'][] = ['value' => $v, 'label' => $labels[$v] ?? null, 'count' => $cnt];
                    }
                }
            }
            if ($fk && !$fk['stored']) {
                $sample['shape'] .= ' · ค่าที่พบ ' . $fk['matched'] . ' จาก ' . $fk['checked'] . ' ค่า มีอยู่ใน ' . $fk['table'] . '.' . $fk['column'];
            }

            $sg = [];
            if (trim((string)$c['description']) === '') {
                $g = null;
                if (isset($same[$ln])) {
                    $g = [$same[$ln][0], 'same', self::LABELS['same'] . ' (' . $same[$ln][1] . ' จาก ' . $same[$ln][2] . ' คอลัมน์)'];
                } elseif (isset(self::DICT[$ln]) && !isset(self::CTX[$ln])) {
                    $g = [self::DICT[$ln], 'dict', self::LABELS['dict']];
                } elseif ($fk && !$fk['stored']) {
                    $subj = $refT ? self::subject((string)$refT['table_name'], $refT['description'] ?? null) : null;
                    $g = [($subj !== null ? 'รหัส' . $subj : 'รหัสอ้างอิง') . ' (อ้างอิงตาราง ' . $fk['table'] . ')', 'data', 'ค่าในคอลัมน์ตรงกับรหัสในตาราง ' . $fk['table']];
                } elseif ($a['desc'] !== null) {
                    $g = [$a['desc'], 'data', 'ตีความจากรูปแบบของข้อมูลที่อ่านมา'];
                } elseif (($x = self::guessColumn($c, $table, [], $refT, ['dict', 'ref', 'pattern'])) !== null) {
                    $g = [$x[0], $x[1], self::LABELS[$x[1]]];
                } elseif ($a['weak'] !== null) {
                    $g = [$a['weak'], 'shape', 'บอกได้แค่ลักษณะของข้อมูล ยังไม่รู้ความหมาย'];
                    $sg['weak'] = true;
                }
                if ($g !== null) {
                    $sg['description'] = $g[0];
                    $sg['source'] = $g[1];
                    $sg['why'] = $g[2];
                }
            }
            if (trim((string)$c['allowed_values']) === '') {
                // รายการค่าครบชุดเสนอได้เมื่อค่ามีไม่กี่แบบเทียบกับจำนวนแถวที่อ่าน
                $few = $listable && count($keys) <= 20 && ($nVals < 30 || count($keys) <= $nVals * 0.5) && max(array_map('mb_strlen', $keys)) <= 40;
                natsort($keys);
                $v = null;
                if ($few && $labels) {
                    $v = implode(', ', array_map(function ($k) use ($labels) {
                        return isset($labels[$k]) ? $k . '=' . $labels[$k] : $k;
                    }, $keys));
                } elseif ($a['allowed'] !== null) {
                    $v = $a['allowed'];
                } elseif (($t = self::valuesFromType($ln, (string)$c['column_type'])) !== null) {
                    $v = $t;
                } elseif (isset($sameVals[$ln])) {
                    $v = $sameVals[$ln][0];
                } elseif ($few && in_array($a['kind'], ['digits', 'code', 'text', 'prefix'], true)) {
                    $v = implode(', ', $keys);
                }
                if ($v !== null) {
                    $sg['allowed_values'] = $v;
                }
            }
            if ((string)$c['ref_table'] === '') {
                if ($fk) {
                    $sg['ref'] = $fk['table'] . '.' . $fk['column'];
                } elseif ($a['kind'] === 'empty' && (string)$c['column_key'] !== 'PRI' && isset($look[$ln])) {
                    $sg['ref'] = $look[$ln][0] . '.' . $look[$ln][1]; // ไม่มีข้อมูลให้ตรวจ จึงเสนอจากชื่อ
                }
            }
            if (!array_diff(array_keys($sg), ['weak'])) {
                $sg = null;
            }
            $out[] = ['id' => (int)$c['id'], 'sample' => $sample, 'suggestion' => $sg];
        }

        // ---- เก็บไว้รอพิจารณา เปิดตารางครั้งหน้าไม่ต้องอ่านฐานข้อมูลต้นทางใหม่
        $stored = self::storeReady(true);
        if ($stored) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
            $pdoDd = db();
            $pdoDd->beginTransaction();
            try {
                $st = $pdoDd->prepare('UPDATE dd_columns SET sample_json = ?, suggest_json = ?, sampled_at = ? WHERE id = ?');
                foreach ($out as $o) {
                    $st->execute([json_encode($o['sample'], $flags), $o['suggestion'] ? json_encode($o['suggestion'], $flags) : null, $at, $o['id']]);
                }
                $pdoDd->commit();
            } catch (Throwable $e) {
                $pdoDd->rollBack();
                throw $e;
            }
        }
        return ['rows_sampled' => $nRows, 'limit' => $limit, 'newest' => (bool)$order, 'stored' => $stored, 'columns' => $out];
    }

    // ------------------------------------------------------------------
    //  ที่เก็บตัวอย่างข้อมูลและข้อเสนอรอพิจารณา (คอลัมน์ sample_json, suggest_json, sampled_at ของ dd_columns)
    // ------------------------------------------------------------------

    /**
     * มีคอลัมน์สำหรับเก็บแล้วหรือยัง $create = ลองเพิ่มให้เองถ้ายังไม่มี (ต้องมีสิทธิ์ ALTER)
     * ถ้าเพิ่มเองไม่ได้ ให้ผู้ดูแลรัน database/migrate_20261110_samples.sql ระบบยังตีความได้ แต่ไม่เก็บผล
     */
    public static function storeReady(bool $create = false): bool
    {
        static $ok = null;
        if ($ok === true || ($ok === false && !$create)) {
            return $ok;
        }
        $ok = (bool)db()->query("SHOW COLUMNS FROM dd_columns LIKE 'suggest_json'")->fetch();
        if (!$ok && $create) {
            try {
                db()->exec("ALTER TABLE dd_columns
                    ADD COLUMN sample_json  MEDIUMTEXT NULL COMMENT 'ตัวอย่างข้อมูลจากฐานข้อมูลต้นทาง (JSON)',
                    ADD COLUMN suggest_json TEXT       NULL COMMENT 'ข้อเสนอที่ระบบเดา รอคนพิจารณา (JSON)',
                    ADD COLUMN sampled_at   DATETIME   NULL COMMENT 'เวลาที่อ่านตัวอย่างข้อมูลล่าสุด'");
                $ok = true;
            } catch (Throwable $e) {
                error_log('[data_dictionary] เพิ่มคอลัมน์เก็บตัวอย่างข้อมูลไม่ได้: ' . $e->getMessage());
            }
        }
        return $ok;
    }

    /** ลบข้อเสนอของคอลัมน์ ทั้งหมดหรือเฉพาะช่อง (description | allowed_values | ref) */
    public static function dismiss(array $col, string $field): void
    {
        if (!self::storeReady() || ($col['suggest_json'] ?? null) === null) {
            return;
        }
        $sg = json_decode((string)$col['suggest_json'], true);
        if (is_array($sg) && $field !== 'all') {
            unset($sg[$field]);
            if ($field === 'description') {
                unset($sg['source'], $sg['why'], $sg['weak']);
            }
        }
        $left = is_array($sg) && $field !== 'all' && array_intersect(array_keys($sg), ['description', 'allowed_values', 'ref']);
        q('UPDATE dd_columns SET suggest_json = ? WHERE id = ?', [$left ? json_encode($sg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $col['id']]);
    }

    /** ตัดข้อเสนอของช่องที่มีคนกรอกไปแล้วด้วยทางอื่น (นำเข้า หรือเดาทั้งโปรเจ็ค) คืนแถวที่ปรับแล้ว */
    public static function prune(array $col): array
    {
        if (($col['suggest_json'] ?? null) === null) {
            return $col;
        }
        $sg = json_decode((string)$col['suggest_json'], true);
        $filled = ['description' => (string)$col['description'], 'allowed_values' => (string)$col['allowed_values'], 'ref' => (string)$col['ref_table']];
        $stale = !is_array($sg);
        foreach ($filled as $k => $v) {
            if (is_array($sg) && isset($sg[$k]) && trim($v) !== '') {
                unset($sg[$k]);
                $stale = true;
            }
        }
        if ($stale) {
            $left = is_array($sg) && array_intersect(array_keys($sg), ['description', 'allowed_values', 'ref']);
            $col['suggest_json'] = $left ? json_encode($sg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            q('UPDATE dd_columns SET suggest_json = ? WHERE id = ?', [$col['suggest_json'], $col['id']]);
        }
        return $col;
    }

    /** ล้างข้อเสนอรอพิจารณาทั้งตาราง $samples = ล้างตัวอย่างข้อมูลด้วย */
    public static function clearTable(array $table, bool $samples): int
    {
        if (!self::storeReady()) {
            return 0;
        }
        return q('UPDATE dd_columns SET suggest_json = NULL' . ($samples ? ', sample_json = NULL, sampled_at = NULL' : '') . ' WHERE table_id = ?', [$table['id']])->rowCount();
    }

    /** อ่านชื่อของรหัสจากตารางรหัส คืน [รหัส => ชื่อ] ไม่อ่านจากตารางรายชื่อบุคคล */
    private static function lookupLabels(array $project, PDO $pdo, callable $qi, array $ref, array $keys): array
    {
        if (!$keys || preg_match(self::PERSON_TABLE, $ref[0])) {
            return [];
        }
        $t = q('SELECT * FROM dd_tables WHERE project_id = ? AND table_name = ? AND is_dropped = 0', [$project['id'], $ref[0]])->fetch();
        if (!$t) {
            return [];
        }
        $names = [];
        foreach (q('SELECT column_name FROM dd_columns WHERE table_id = ? AND is_dropped = 0 ORDER BY ordinal_position, id', [$t['id']]) as $r) {
            $names[strtolower((string)$r['column_name'])] = (string)$r['column_name'];
        }
        $tl = strtolower($ref[0]);
        $label = null;
        foreach (['name', $tl . '_name', $tl . 'name', 'name_th', 'thai_name', 'thainame', 'title', 'description', 'detail'] as $cand) {
            if (isset($names[$cand]) && $cand !== strtolower($ref[1])) {
                $label = $names[$cand];
                break;
            }
        }
        if ($label === null) {
            foreach ($names as $ln => $orig) {
                if ($ln !== strtolower($ref[1]) && preg_match('/(name|desc|title)$/', $ln)) {
                    $label = $orig;
                    break;
                }
            }
        }
        if ($label === null || !isset($names[strtolower($ref[1])])) {
            return [];
        }
        $pg = $project['conn_driver'] === 'pgsql';
        $from = $pg ? $qi(($project['conn_schema'] ?? '') !== '' ? (string)$project['conn_schema'] : 'public') . '.' . $qi($t['table_name']) : $qi($t['table_name']);
        $pk = $qi($names[strtolower($ref[1])]);
        try {
            $st = $pdo->prepare('SELECT ' . ($pg ? "CAST($pk AS text)" : $pk) . ' AS k, ' . $qi($label) . " AS v FROM $from WHERE "
                . ($pg ? "CAST($pk AS text)" : $pk) . ' IN (' . implode(',', array_fill(0, count($keys), '?')) . ') LIMIT 100');
            $st->execute(array_values($keys));
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $v = trim((string)$r['v']);
                if ($v !== '') {
                    $out[trim((string)$r['k'])] = mb_strlen($v) > 60 ? mb_substr($v, 0, 60) . '…' : $v;
                }
            }
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) {
            return []; // ไม่มีสิทธิ์อ่านตารางรหัส หรือชนิดข้อมูลไม่ตรงกัน: แสดงเฉพาะรหัส
        }
    }
}
