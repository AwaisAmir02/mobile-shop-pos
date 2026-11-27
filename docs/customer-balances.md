# What a customer owes: which balance to use

A customer can owe the shop two different kinds of money, and they are kept apart:

- **Udhaar**: loans in the udhaar book (`udhaar_transactions`). The balance can go
  negative when the customer paid ahead (an *advance*).
- **Bills**: the unpaid part of their sales and counter services (wallet loads,
  bill payments, repairs and so on). A bill is never below zero.

Several screens need "what they owe", and they ask different questions. Use the one
that answers your question. Don't build a fifth.

| Question | Use | Udhaar advance | Where it shows |
|---|---|---|---|
| Everything they owe right now, one figure | `CustomerDuesStatement::for($c)->total()` | **Offsets** bills | Invoice "Total balance", receipt PDF, share & remind and WhatsApp reminders, the customer's ledger link, Receive Payment, order and load-sheet "owes" |
| The same figure for every customer at once | `UdhaarReport::rows()` (`balance`), `totalDue()` | **Offsets** bills | Udhaar list, Customers list Balance, customer page "Total Owed" |
| How much of their credit limit is used | `CreditControl::creditUsed($c)` (and `check()`) | **Ignored**: never frees up credit | Register credit panel, order form, customer Credit card |
| How long it has been owed | `DuesAging` (`rows()`, `forCustomer()`) | Kept **separate**: two tables, bills and udhaar | Dues Aging report, customer "How long it's owed" |
| Unpaid sales only | `Customer::salesOutstandingBalance()` | Not included | Customer page "Sales Outstanding", contra |

## Rules behind them

- **The two total figures always agree.** `CustomerDuesStatement::total()` and the
  `UdhaarReport` balance add up the same records (`UdhaarReport::dueRecords()`, the
  `Sale::owing()` scope), one customer or all at once. If you change what counts as
  a bill, change it there and both follow.
- **An advance can pay a bill, but it can't buy more credit.** Showing what someone
  owes, an advance is netted. Checking a credit limit, it isn't: a customer Rs 5,000
  ahead in udhaar with Rs 20,000 of bills has used Rs 20,000 of credit.
- **Aging never nets.** Bills age from their own date (and are overdue past their due
  date). Udhaar ages by loan, oldest repaid first, as `CustomerPayments` allocates.
  Its bills total equals the bills part of the Udhaar list.
- **Shop-wide.** Every one of these ignores the viewer's branch: a customer's dues
  never depend on where you are standing.
- **Cheques in hand count for nothing** until they clear (`Cheques::inHand()` is shown
  beside these figures, never inside them).

## Cash on delivery with a courier (online sellers)

This is not another balance. It is a state a bill can be in, and while it's in that
state the bill isn't the customer's.

When an online seller (`OnlineTool::CourierSettlements`) sends a sale by courier with
cash to collect, the parcel is `cod_tracked`. From then until the courier pays out,
the unpaid part of that sale is owed **by the courier**, not the customer:

- On its way, delivered but not yet paid out, or coming back and not yet received:
  `Shipment::heldByCourier()`.
- Every figure above reads bills through `Sale::owedByCustomer()` (`owing()` less those
  sales): Udhaar, the customer's statement and reminders, the ledger link, Receive
  Payment, credit used, aging and Sales Outstanding all leave it out. The Party Ledger
  statement shows the sale and a matching "With *courier* to collect" credit, so it
  nets to nothing.
- The shop is still owed the money. The sale stays unpaid (`amountDue()`), the sale
  page says "With *courier*", Sales Outstanding on the dashboard and the Sales report
  still count it, and Courier Payouts shows what each courier holds
  (`ReportQueries\CourierBalances`).
- A courier payout (`CourierSettlements`) pays each sale what was collected on it
  (payment method `courier`, which moves no money by itself). Only the net reaches
  Cash & Balances, once, on the payout's date. If the courier paid less than the bill,
  the rest is the customer's again, as an ordinary bill.
- A refused parcel stays out of dues until it is received back. Then the shop cancels
  the sale, or keeps it, and it is the customer's again.

Every other shop's parcels are not tracked, so their COD sales stay in customer dues
exactly as before.

## Deposits and gift vouchers (service businesses)

Neither is another "what they owe" figure. Both are money the shop holds **for**
someone, like store credit, and never appear in Udhaar, dues, statements or aging.

- A **deposit** (`CustomerDeposits`, taken on a booking or a job card) belongs to one
  customer. It is held until it pays part of their bill (sale payment method
  `deposit`), is refunded, or is kept by the shop (a no-show), which makes it income.
  What is left is worked out from bills that still stand, so cancelling the bill gives
  it back. The customer page lists deposits held.
- A **gift voucher** (`GiftVouchers`) belongs to whoever has the code. It is spent at
  New Sale (payment method `gift_voucher`), part by part.
- Cash & Balances counts the money once, when it comes in (source "Deposits & gift
  vouchers") and when a deposit is refunded; using either on a bill moves no money.
- Ledger: Customer Deposits (2210, per customer) and Gift Vouchers Outstanding (2220)
  are liabilities; kept deposits go to Forfeited Deposits (4470). All three are created
  on first use, so other shops' charts don't change. An unspent voucher past its date
  stays a liability until the accountant decides when to take it to income.

A membership or package sold on a bill is an ordinary line on that bill: paid or owed
like anything else on it.

### Known simplification: membership revenue (pending accountant review)

A membership or package is counted as income **on the day it is sold**, in full: the
bill's line goes to Service & Membership Sales (4210) and the money to Cash & Balances
once. It is **not** spread over the months it covers, and unused package sessions are
not held back as a liability. This is how a small shop's own books usually work, but
it isn't accrual accounting: a 12-month membership sold in December shows all its
income in December.

This is deliberate and unchanged until an accountant decides the treatment (deferred
revenue released month by month, and what happens to unused sessions or a membership
cancelled part-way). Nothing else depends on it: dues, statements, commission and the
Members screen don't change if revenue recognition does. The same review should cover
gift vouchers past their date, which stay in Gift Vouchers Outstanding (2220) for now.
