<?php
if (! defined('ABSPATH')) {
    exit();
}

// domPDF and dependencies
require_once ('lib/vendor/autoload.php');
use Dompdf\Dompdf;

class MemberMouse_Receipt
{
    private $isTest = false;
    private $additionalCCEmail = "";
    private $testEmail = "";
    private $eventType = "";
    private $member_id = "";
    private $fname = "";
    private $lname = "";
    private $email = "";
    private $ccEmail = "";
    private $address1 = "";
    private $address2 = "";
    private $city = "";
    private $state = "";
    private $zip = "";
    private $country = "";
    private $extra_info = "";
    private $product_name = "";
    private $order_currency = "";
    private $order_subtotal = "";
    private $order_discount = "";
    private $order_shipping = "";
    private $order_total = "";
    private $order_number = "";
    private $message = "";

    /**
     * A reference to an instance of this class.
     */
    private static $instance;

    /**
     * Returns an instance of this class.
     */
    public static function get_instance()
    {
        if (null == self::$instance) {
            self::$instance = new MemberMouse_Receipt();
        }
        return self::$instance;
    }

    /**
     * Initializes the plugin by setting filters and administration functions.
     */
    public function __construct()
    {
        $this->plugin_name = 'membermouse-pdf-receipts';
        $this->today = date('M. j, Y');
    }

    /**
     * Process Payment Received Hook
     *
     * - Check if payment_received is only triggered on initial purchase.
     * - Check if rebill is only triggered on rebill
     * - Check if rebill is triggered on declines
     */
    public function process_payment_received($data)
    {
        try { 
            // verify all required data has been configured
            $businessName = get_option("mm-pdf-business-name", false);
            $businessAddress = get_option("mm-pdf-business-address", false);
            $emailFromId = get_option("mm-pdf-email-from", false);
            $emailSubject = get_option("mm-pdf-email-subject", false);
            $emailBody = get_option("mm-pdf-email-body", false);
            
            $emailTemplateCheck = (!empty($emailSubject) && !empty($emailBody) && !empty($emailFromId)) ? true : false;
            $pdfConfigCheck = (!empty($businessName) && !empty($businessAddress)) ? true : false;
            $pdfInvoicingActive = ($emailTemplateCheck && $pdfConfigCheck) ? true : false;
            
            if($pdfInvoicingActive)
            {
                $this->setData($data);
        
                $pdfPath = false;
                $pdfPath = $this->createPDF();
        
                if ($pdfPath !== false) 
                {
                    $this->sendEmail($pdfPath);
        
                    // remove file
                    unlink($pdfPath);
                }
            }
        } catch (Exception $e) {
            // PDF generation and emailing failed for some reason. Catching error so that it doesn't
            // interfere with the order process
        }
    }

    public function sendTest($toEmail)
    {
        $this->isTest = true;
        $this->testEmail = $toEmail;
        
        global $wpdb;
        
        $sql = "SELECT id FROM ".MM_TABLE_ORDERS." ORDER BY date_added DESC LIMIT 1;";
        $orderId = $wpdb->get_var($wpdb->prepare($sql));
        
        if (!is_null($orderId))
        {
            $order = new MM_Order($orderId);
            $data = MM_Event::packageOrderData($order->getCustomer()->getId(), $order->getId());
            $this->process_payment_received($data);
        }
        else
        {
            $response = new MM_Response();
            $response->type = MM_Response::$ERROR;
            $response->message = "There must be at least one order placed in MemberMouse in order to run a test.";
            return $response;
        }
        
        return new MM_Response();
    }
    
    public function resendReceipt($order, $additionalCCEmail)
    {   
        if ($order instanceof MM_Order && $order->isValid())
        {
            $this->additionalCCEmail = $additionalCCEmail;
            $data = MM_Event::packageOrderData($order->getCustomer()->getId(), $order->getId());
            $this->process_payment_received($data);
        }
        else
        {
            $response = new MM_Response();
            $response->type = MM_Response::$ERROR;
            $response->message = "Unable to resend receipt. A valid order is required.";
            return $response;
        }
        
        return new MM_Response();
    }
    
    /**
     * Set Data
     */
    private function setData($data)
    {
        $this->eventType = $data["event_type"];
        $this->member_id = $data['member_id'];
        $this->fname = $data['first_name'];
        $this->lname = $data['last_name'];
        $this->email = $data['email'];
        $this->address1 = $data['billing_address'];
        $this->address2 = $data['billing_address2'];
        $this->city = $data['billing_city'];
        $this->state = $data['billing_state'];
        $this->zip = $data['billing_zip_code'];
        $this->country = $data['billing_country'];
        $order_products = json_decode($data['order_products'], true)[0];
        $this->product_name = $order_products['name'];
        $this->order_subtotal = $data['order_subtotal'];
        $this->order_discount = $data['order_discount'];
        $this->order_shipping = $data['order_shipping'];
        $this->order_total = $data['order_total'];
        $this->order_number = $data['order_number'];
        $this->order_currency = isset($data['order_currency']) ? $data['order_currency'] : "";
        
        $billingCustomFieldId = get_option("mm-pdf-email-billing-custom-field-id", false);
        
        if(!empty($billingCustomFieldId) && isset($data['cf_'.$billingCustomFieldId]))
        {
            $this->extra_info = nl2br($data['cf_'.$billingCustomFieldId]);
        }
        
        $emailCCFieldId = get_option("mm-pdf-email-cc-field-id", false);
        
        if(!empty($emailCCFieldId) && isset($data['cf_'.$emailCCFieldId]))
        {
            $this->ccEmail = trim($data['cf_'.$emailCCFieldId]);
            
            // validate email
            if (!filter_var($this->ccEmail, FILTER_VALIDATE_EMAIL)) 
            {
                // invalid email. clear it. 
                $this->ccEmail = "";
            }
        }
    }

    /**
     * Create PDF
     */
    private function createPDF()
    {
        $pdfName = ($this->isTest) ? "test_billing_receipt_" : "billing_receipt_";
        $pdfName .= $this->order_number."_";
        $tmp_prefix = tempnam(sys_get_temp_dir(), $pdfName);

        // php functions don't provide a way to add extension, so we use them to find a writeable dir and then
        // add the extension ourselves
        $full_path = $tmp_prefix . ".pdf";
        $renameReturnValue = rename($tmp_prefix, $full_path);
        if ($renameReturnValue) {
            $dompdf = new Dompdf();

            // Load HTML
            $dompdf->loadHtml($this->generatePDFHtml());

            // Render the HTML as PDF
            $dompdf->render();

            // Output the generated PDF to variable
            $pdf_gen = $dompdf->output();

            if (file_put_contents($full_path, $pdf_gen) !== false) {
                // Saved PDF to file
                return $full_path;
            }
        }
        return false;
    }

    /**
     * Generates PDF HTML
     */
    private function generatePDFHtml()
    {
        ob_start();
        
        $businessName = get_option("mm-pdf-business-name", false);
        $businessAddress = get_option("mm-pdf-business-address", false);
        $businessTaxLabel = get_option("mm-pdf-business-tax-label", false);
        $businessTaxId = get_option("mm-pdf-business-tax-id", false);
        $receiptFooterSection1 = get_option("mm-pdf-footer-section-1", false);
        $receiptFooterSection2 = get_option("mm-pdf-footer-section-2", false);
        ?>
<!DOCTYPE html>
<html>

<head>
<meta charset='utf-8'>
<title><?php echo $businessName; ?> Receipt</title>
<link
	href="https://fonts.googleapis.com/css?family=Open+Sans&display=swap"
	rel="stylesheet">
<link rel="stylesheet"
	href="<?= plugin_dir_path(__FILE__) .'css/receipt.css'; ?>">
</head>

<body>
	<div class="pdf-container">
		<div class="row title-row">
			<p>
				<?php if($this->isTest) { ?>
				<strong><span style="color:#c00">TEST RECEIPT</span></strong><br/>
				<?php } ?>
				<strong><?php echo $businessName; ?></strong><br /> 
				<?php echo $businessAddress; ?><br/>
				<?php if(!empty($businessTaxId)) { ?>
				<?php echo $businessTaxLabel; ?> <?php echo $businessTaxId; ?>
				<?php } ?>
			</p>
		</div>

		<div class="row receipt-table">
			<div class="receipt-top">
				<div class="receipt-info">
					<div>
						<strong>MEMBER ID:</strong> <?= $this->member_id; ?></div>
					<br /> <br />
                <?php if($this->extra_info) : ?>
                  <div><?= $this->extra_info; ?></div>
                <?php else: ?>
                 	<div><?= $this->fname; ?> <?= $this->lname; ?></div>
					<div><?= $this->email; ?></div>
					<div><?= $this->address1; ?></div>
                	<?php if($this->address2) : ?>
                  	<div><?= $this->address2; ?></div>
                	<?php endif; ?>
                	<div><?= $this->city; ?> <?= ($this->city && $this->state)?",":""; ?> <?= $this->state; ?> <?= $this->address1?$this->zip:""; ?></div>
                <?php endif; ?>
              </div>
				<div class="receipt-date">
					<div>
						<strong>DATE PAID:</strong> <?= $this->today; ?></div>
				</div>
			</div>
            <?php if(!empty($this->order_currency)) { ?>
            <div class="receipt-top-extra">
				<p>
					<em>All prices in <?php echo $this->order_currency; ?></em>
				</p>
			</div>
			<?php } ?>
			
            <table>
				<thead>
					<tr>
						<th class="left-align">Service Description</th>
						<th class="right-align">Order #</th>
						<th></th>
						<th class="right-align">Amount</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?= $this->product_name; ?></td>
						<td class="right-align"><?= $this->order_number; ?></td>
						<td class="right-align">Subtotal</td>
						<td class="right-align"><?= _mmf($this->order_subtotal, $this->order_currency); ?></td>
					</tr>
				<?php if(isset($this->order_shipping) && floatval($this->order_shipping) > 0) : ?>
                	<tr>
						<td></td>
						<td></td>
						<td class="right-align">Shipping</td>
						<td class="right-align"><?= _mmf($this->order_shipping, $this->order_currency); ?></td>
					</tr>
                <?php endif; ?>
                <?php if(isset($this->order_discount) && floatval($this->order_discount) > 0) : ?>
                	<tr>
						<td></td>
						<td></td>
						<td class="right-align">Discount</td>
						<td class="right-align"><?= _mmf($this->order_discount, $this->order_currency); ?></td>
					</tr>
                <?php endif; ?>
					<tr>
						<td>&nbsp;</td>
						<td>&nbsp;</td>
						<td>&nbsp;</td>
						<td>&nbsp;</td>
					</tr>
                	<tr>
						<td></td>
						<td></td>
						<td class="total-paid-td right-align first"><strong>TOTAL PAID</strong></td>
						<td class="total-paid-td right-align"><strong><?= _mmf($this->order_total, $this->order_currency); ?></strong></td>
					</tr>
					<tr>
						<td>&nbsp;</td>
						<td>&nbsp;</td>
						<td>&nbsp;</td>
						<td>&nbsp;</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="receipt-bottom">
			<?php echo $receiptFooterSection1; ?>
		</div>
		<div class="receipt-footer">
			<?php echo $receiptFooterSection2; ?>
		</div>

	</div>
</body>

</html>
<?php
        return ob_get_clean();
    }

    /**
     * Send email to member
     */
    private function sendEmail($pdfPath)
    {
        $emailFromId = get_option("mm-pdf-email-from", false);
        $emailSubject = get_option("mm-pdf-email-subject", false);
        $emailBody = get_option("mm-pdf-email-body", false);

        if (empty($emailFromId) || empty($emailSubject) || empty($emailBody)) {
            return false;
        }
        
        $fromEmployee = new MM_Employee($emailFromId);
        $user = new MM_User($this->member_id);
        $order = MM_Order::getDataByOrderNumber($this->order_number);
        $context = new MM_Context($user, $fromEmployee, $order);

        if ($this->eventType == MM_Event::$PAYMENT_REBILL) {
            $orderAttributes = array(
                "is_rebill" => true
            );
        } else {
            $orderAttributes = array(
                "is_rebill" => false
            );
        }

        $context->setOrderAttributes($orderAttributes);

        $email = new MM_Email();
        $email->setContext($context);
        $email->setBody($emailBody);
        $email->setFromName($fromEmployee->getDisplayName());
        $email->setFromAddress($fromEmployee->getEmail());
        
        if(!empty($this->ccEmail))
        {
            $email->addCC($this->ccEmail);
        }
        
        if(!empty($this->additionalCCEmail))
        {
            $email->addCC($this->additionalCCEmail);  
        }
        
        $email->setAttachments(array(
            $pdfPath
        ));
        $email->setToName($this->fname);
        
        if($this->isTest)
        {
            $email->setSubject("[TEST] ".$emailSubject);
            $email->setToAddress($this->testEmail);
            $email->disableLogging();
        }
        else 
        {   
            $email->setToAddress($this->email);
            $email->setSubject($emailSubject);
        }

        $email->send();
    }
}