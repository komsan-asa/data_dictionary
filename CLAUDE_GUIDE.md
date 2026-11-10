# คู่มือ API สำหรับ Claude และระบบอื่น

ทุกคำสั่งเรียกที่ `http://<เครื่องแม่ข่าย>/data_dictionary/api.php?action=<คำสั่ง>`
ส่ง header `X-API-Key: <key ของโปรเจ็ค>` ทุกครั้ง (สร้าง key ได้ที่ปุ่ม "API สำหรับ Claude" ในหน้าเว็บ)
key ผูกกับโปรเจ็คเดียว จึงไม่ต้องส่งรหัสโปรเจ็ค คำตอบเป็น JSON มี `"ok": true` หรือ `"ok": false, "error": "..."`

## คำสั่งอ่าน (GET)

| action | พารามิเตอร์ | ได้อะไร |
|---|---|---|
| `project` | | ชื่อโปรเจ็คและสถิติ (จำนวนตาราง คอลัมน์ ที่มีคำอธิบายแล้ว) |
| `tables` | `q`, `filter` = all / undocumented / documented / dropped, `page`, `per` | รายการตาราง |
| `table` | `name` | ตารางเดียว พร้อมคอลัมน์และ index |
| `search` | `q` | ค้นหาคอลัมน์ทั้งโปรเจ็คตามชื่อหรือความหมาย |
| `missing` | `limit` (สูงสุด 100), `after` | ตารางที่ยังมีช่องความหมายว่าง ส่งทีละชุด หน้าถัดไปใส่ `after` = ค่า `next_after` |
| `export` | `format` = json / md / csv, `tables` = a,b,c | ทั้ง dictionary หรือเฉพาะตารางที่ระบุ |

## ส่งคำอธิบาย (POST `action=import`)

```json
{
  "source": "claude",
  "overwrite": false,
  "tables": [
    {
      "name": "ovst",
      "description": "การมารับบริการผู้ป่วยนอก 1 แถวต่อ 1 ครั้งที่มา",
      "module": "OPD",
      "notes": "",
      "columns": [
        { "name": "vn", "description": "เลขที่การมารับบริการ" },
        { "name": "hn", "description": "เลขประจำตัวผู้ป่วย", "ref": "patient.hn" },
        { "name": "ovstist", "description": "ประเภทการมา", "allowed_values": "01=มาเอง, 02=นัดมา" }
      ]
    }
  ]
}
```

- `overwrite: false` (ค่าเริ่มต้น) เติมเฉพาะช่องที่ยังว่าง ไม่ทับสิ่งที่คนเขียนไว้ ใส่ `true` เมื่อต้องการแก้ของเดิม
- โปรเจ็คที่ซิงค์จากฐานข้อมูลจริงแล้ว: ตาราง/คอลัมน์ที่ไม่มีอยู่จะไม่ถูกสร้าง และรายงานกลับใน `not_found` (กันพิมพ์ชื่อผิด) ถ้าต้องการให้สร้างใส่ `"create_missing": true`
- โปรเจ็คที่ยังไม่เคยซิงค์: สร้างตาราง/คอลัมน์ให้เอง และรับโครงสร้างได้ด้วย โดยเพิ่มในแต่ละคอลัมน์ `"type": "varchar(9)"`, `"nullable": false`, `"key": "PRI"`, `"default": "0"`
- ส่งครั้งละหลายตารางได้ ทั้งชุดสำเร็จหรือล้มเหลวพร้อมกัน

## ขั้นตอนที่แนะนำเมื่อให้ Claude เติมความหมาย

1. `GET ?action=missing&limit=20` อ่านตารางที่ยังว่าง
2. ดูโค้ดของโปรเจ็คว่าแต่ละคอลัมน์ถูกใช้ทำอะไร แล้วเขียนความหมายเป็นภาษาไทย กระชับ ถ้าไม่แน่ใจให้เว้นว่าง อย่าเดา
3. `POST ?action=import` ส่งกลับ
4. ทำซ้ำด้วย `&after=<next_after>` จนกว่า `next_after` เป็น `null`

## ตัวอย่างเรียกด้วย curl

```bash
curl -H "X-API-Key: dd_xxxxx" "http://server/data_dictionary/api.php?action=missing&limit=10"

curl -H "X-API-Key: dd_xxxxx" -H "Content-Type: application/json" \
     -d @descriptions.json "http://server/data_dictionary/api.php?action=import"
```
