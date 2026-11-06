# WhatsApp multiple devices

The phone service at `wa.rslsolution.com` already allows more than one linked device (several slots, each with its own name and pair state). Linked device lists those phones. A WhatsApp sheet saves which of them will send, and the day and gap. The rows are not sent yet. An email sheet still picks one mailbox.

This work is local only. Do not deploy it, and do not commit it, until asked. Do not send WhatsApp rows through email, and do not add this dropdown to email sheets.

## Already on the local desk

- The WhatsApp sidebar item **Overview** is renamed **Dashboard**. The email desk keeps its own Overview name. The page is the campaign report already built (sent, failed, numbers, replies waiting, ring, daily bars).
- **Linked device** lists every phone from `GET /api/devices`, not only the first one. Each phone is one full-width row.
- The header shows the name, how many phones are on the account, a **used / 5 slots** count, and **Add device**. Add device asks for a name, then creates that phone. It does not drop the phones already listed.
- A phone that is not linked shows **Not linked yet**, **Not linked**, and **Pair**. Pair opens the scan picture on that same row. The picture should appear in about 1 to 3 seconds. **Pair** hides while the picture is open. **Close** puts it back.
- When that phone connects, the row changes on its own: the line under the name becomes **Linked** (or the phone number when the service sends one), the status becomes **Ready**, the scan picture closes, and **Disconnect** replaces **Pair**. **Disconnect** is only on a connected phone, and it removes only that phone.
- The phone service currently returns `phone_number` empty even after connect, so the desk shows **Linked** until a number is present.
- Send from on an open WhatsApp sheet is a dropdown. **All ready phones** uses every phone that is Ready, including a phone that becomes Ready later. Or tick specific phones. The sheet saves that choice. Each selected phone is 20 messages a day. The page shows that count and how many days the ready rows need. A phone that is not ready is left out. The last remaining phone stays selected.
- The same sheet has **Start** and **Gap**. Start is the day those rows begin. Gap is 1 to 30 minutes between two messages on each selected phone. These values are saved on the sheet. Rows are not sent yet.
- WhatsApp column mapping, the row table, and Edit record do not include email. The fields are ID, name, phone, and the WhatsApp message.
- Dashboard has a phone choice next to the date range. **All devices** keeps the totals. Choosing one phone limits Sent, Failed, Numbers, Replies waiting, the ring, the daily bars, and both lists to what that phone sent. It does not change which phone a sheet sends from.
- Chats has a sending-phone choice. **All** shows every campaign chat. Choosing one phone shows only the customers that phone messaged, and the open thread is that phone's conversation.
- Opening a chat saves that thread on this desk, including the customer's replies. If the inbox cannot be read later, the saved messages still show.

## Still to build

Send that sheet's rows from the phones selected in the dropdown. The database for that send is in place. The rows are not queued yet.

## Dashboard device filter

This is on the page. The choice sits once, next to the date range, and every card uses it.

- All devices shows the campaign totals already on the page.
- Choosing one linked number limits Sent, Failed, Numbers, Replies waiting, the ring, the daily bars, and both lists to messages that phone sent.
- Switching the device updates the counts. It does not change which phone a sheet is set to send from.

## Sheet page

The open WhatsApp sheet keeps the workbook and the rows. Send from is a dropdown. An email sheet still picks one mailbox.

A WhatsApp row does not use email. Column mapping does not offer an email field. The table has no Email column. Edit record has no Email box. A workbook may still contain an email column; this desk ignores it. The fields that stay are ID, name, phone, and the WhatsApp message.

- Send from is a dropdown. **All ready phones** uses every phone that is Ready, and a phone that becomes Ready later is included. Or tick the specific phones, one or several. The sheet saves that choice. Each selected phone sends 20 messages a day.
- Start and Gap are on the open sheet and are saved with it. Start is the day those rows begin. Gap is 1 to 30 minutes between two messages on each ready phone.
- Each ready phone sends at most 20 rows that day. One ready phone is 20 a day. Five ready phones are 100 a day. The sheet shows how many days the ready rows need. Rows past a phone’s 20 move to the next day. Sending those rows is not running yet.
- A row stays Waiting until its turn, then becomes Sent or Failed. If the number is blocked, the remaining rows do not go out.
- The phone list itself stays on Linked device. This page only picks the phones and the schedule for these rows.

## Chats by sending number

This is on the page. Several phones can send at the same time, so a chat belongs to the linked number that sent it.

- Chats has one dropdown of the linked numbers, plus All.
- All shows every campaign chat. Choosing one number shows only the chats that number sent.
- The open thread is that number's conversation with the customer.

## Chat history

This is on the page for a chat you open. History is stored on this desk, not only in the live inbox.

- A thread stays after a refresh, after the inbox is slow, and after that sending number is no longer linked.
- If the customer's number is blocked, the history already saved stays on the desk. New sends to that customer stop.
- If the linked sending phone itself is blocked, the same rule applies. Chats and Sent that went out from that phone stay, with the message and the time. New sends from that phone stop. The phone stays on Linked device so those old rows can still name it, and it cannot be chosen for a new send.
- Each message keeps which linked phone sent it, so two phones messaging the same customer do not wipe each other's thread.

## Sent

This is on the page. Sent uses the same dropdown as Chats: All, or one linked number.

- All lists every send. Each row shows which linked phone sent it.
- Choosing one number lists only what that phone sent.
- A customer who was messaged from two phones has two rows, one for each phone. Each row is still the latest message from that phone to that customer.
- Clicking a row opens that phone's chat with the customer.
- The row stays after the sending phone is unlinked, after the customer's number is blocked, and after the linked sending phone itself is blocked. Status can show Blocked. The message text and the time stay.

## Scheduler

Start, Gap, and the 20-a-day count are saved on the sheet. Sending the rows on that schedule is not running yet. Two linked numbers can send on the same day. Each number has its own 20 and its own gap. A blocked customer number does not receive more messages. A blocked linked phone does not send more messages. History from that phone stays.

## Database

Nothing is deleted when a customer number or a linked phone is blocked. Rows stay. Status changes, and new sends stop.

The desk keeps one account (`whatsapp_account`). The tables below are on this database. Nothing in them is removed when a phone is blocked.

- `whatsapp_devices` keeps each linked phone: the platform device id, the name, the number, and the state (ready, disconnected, or blocked). Opening Linked device saves the current list. A phone that later disappears stays here as disconnected. A blocked row stays blocked, so old chats can still name it.
- `whatsapp_chats` is one thread per sending phone plus customer phone. Each chat stores the device id. The three chats already on this desk belong to Whatsapp Campaign and were not copied. A second phone messaging the same customer gets its own chat.
- `whatsapp_chat_messages` keeps the body, the direction, the time, and the sending phone for a chat that has been opened, including replies. No row is removed on a block. A chat that has not been opened yet has only the outbound send log.
- `whatsapp_sends` stores the device id, the time the row is due (`due_at`), and the status Waiting, Sent, Failed, or Blocked. The five sends already logged now point at Whatsapp Campaign. Their due time stays empty until sending runs. The daily cap of 20 is a count of Sent rows for that device on that day, not a separate table.
- `whatsapp_sheet_sources` stores the ready phone ids, the start date, and the delay in minutes. Mapping does not include email.

## What this does not change

- Delivered, read, and replied counts stay off the dashboard until those ticks are stored on the send log.
- The 20-a-day limit, the gap, and the send date are not set on Linked device.
