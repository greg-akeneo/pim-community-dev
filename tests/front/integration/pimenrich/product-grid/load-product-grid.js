const UserBuilder = require('../../../common/builder/user');
const adminUser = new UserBuilder().withUsername('admin').build();
const datagridLoad = require('./responses/datagrid-load.json');
const { readFileSync } = require('fs');

const mockResponses = {
  'http://pim.com/js/extensions.json': ['application/json', require(`${process.cwd()}/public/js/extensions.json`)],
  'http://pim.com/rest/security/': ['application/json', require('./responses/rest-security.json')],
  'http://pim.com/configuration/locale/rest?activated=true': ['application/json', require('./responses/activated-locales.json')],
  'http://pim.com/localization/format/date': ['application/json', require('./responses/date-format.json')],
  'http://pim.com/datagrid_view/rest/product-grid/default-columns': ['application/json', require('./responses/default-columns.json')],
  'http://pim.com/datagrid_view/rest/product-grid/default': ['application/json', require('./responses/default-views.json')],
  'http://pim.com/rest/user/': ['application/json', adminUser],
  'http://pim.com/js/translation/en_US.js': ['application/javascript', readFileSync(`${process.cwd()}/public/js/translation/en_US.js`, 'utf-8')],
  'http://pim.com/enrich/product-category-tree/product-grid/list-tree.json?dataLocale=undefined&select_node_id=0&include_sub=1&context=view': [
    'application/json', require('./responses/list-tree.json')
  ],
  'http://pim.com/enrich/product-category-tree/product-grid/children.json?dataLocale=undefined&context=view&id=1&select_node_id=-2&with_items_count=1&include_sub=1': [
    'application/json', require('./responses/category-tree.json')
  ],
}

const mockFilteredResponse = async (page, responses) => {
  return page.on('request', (interceptedRequest) => {
    const response = responses[interceptedRequest.url()]

    if (response) {
      const { productGridData } = constructProductsResponse(response)

      return interceptedRequest.respond({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(productGridData),
      })
    }
  })
}

const constructProductsResponse = (products) => {
  const productGridData = {
    data: products,
    totalRecords: products.length,
    options: {
      totalRecords: products.length
    }
  }

  const productLoadData = Object.assign(datagridLoad, {
    data: JSON.stringify(productGridData)
  })

  return { productGridData, productLoadData };
}

const buildProductGridResponses = (page, products = [], filters = []) => {
  const { productGridData, productLoadData } = constructProductsResponse(products)

  const productGridResponses = {
    'http://pim.com/datagrid/product-grid?dataLocale=en_US&product-grid%5B_pager%5D%5B_page%5D=1&product-grid%5B_pager%5D%5B_per_page%5D=25&product-grid%5B_parameters%5D%5Bview%5D%5Bcolumns%5D=identifier%2Cimage%2Clabel%2Cfamily%2Cenabled%2Ccompleteness%2Ccreated%2Cupdated%2Ccomplete_variant_products%2Csuccess%2C%5Bobject%20Object%5D&product-grid%5B_parameters%5D%5Bview%5D%5Bid%5D=&product-grid%5B_sort_by%5D%5Bupdated%5D=DESC&product-grid%5B_filter%5D%5Bscope%5D%5Bvalue%5D=ecommerce&product-grid%5B_filter%5D%5Bcategory%5D%5Bvalue%5D%5BtreeId%5D=1&product-grid%5B_filter%5D%5Bcategory%5D%5Bvalue%5D%5BcategoryId%5D=-2&product-grid%5B_filter%5D%5Bcategory%5D%5Btype%5D=1': [
      'application/json', productGridData
    ],
    'http://pim.com/datagrid/product-grid/load?dataLocale=en_US&params%5BdataLocale%5D=en_US&product-grid%5B_parameters%5D%5Bview%5D%5Bcolumns%5D=identifier%2Cimage%2Clabel%2Cfamily%2Cenabled%2Ccompleteness%2Ccreated%2Cupdated%2Ccomplete_variant_products%2Csuccess%2C%5Bobject+Object%5D': [
    'application/json', productLoadData
    ],
    'http://pim.com/datagrid/product-grid/attributes-filters?page=1&locale=en_US': ['application/json', filters]
  };

  return page.on('request', (interceptedRequest) => {
    const response = Object.assign(mockResponses, productGridResponses)[interceptedRequest.url()];

    if (!response) {
      return interceptedRequest.continue();
    }

    const [contentType, body] = response;

    return interceptedRequest.respond({
      status: 200,
      contentType: contentType,
      body: typeof body === 'string' ? body : JSON.stringify(body),
    })
  })
}

const getOperatorChoiceByLabel = async (filter, choiceLabel) => {
  const operatorChoices = await filter.$$('.operator_choice');
  let matchingChoice = null;

  for(let i = 0; i < operatorChoices.length; i++) {
    const text = await (await operatorChoices[i].getProperty('textContent')).jsonValue();
    if (text.trim() === choiceLabel) {
      matchingChoice = operatorChoices[i];
      break;
    }
  }

  return matchingChoice;
}

const getProductRowLabels = async (page) => {
  const rows = await page.$$('.grid .AknGrid-bodyRow.row-click-action');
  const rowLabels = [];

  for (row of rows) {
    const labelColumn = await row.$('[data-column="label"]');
    const rowLabel = await (await labelColumn.getProperty('textContent')).jsonValue();
    if (rowLabel) rowLabels.push(rowLabel);
  }

  return rowLabels;
}

// const getProductRowByLabel = async (page, label) => {
//   let matchingRow = null;

//   for (row of rows) {
//     const labelColumn = await row.$('[data-column="label"]');
//     const rowLabel = await (await labelColumn.getProperty('textContent')).jsonValue();
//     if (rowLabel.trim() === label) {
//       matchingRow = row;
//       break;
//     }
//   }

//   return matchingRow;
// }

const waitForLoading = (page) => {
  return page.waitForSelector('.AknLoadingMask.loading-mask', {hidden: true});
}

const loadProductGrid = async (page, products, filters, filteredResponses) => {
  await buildProductGridResponses(page, products, filters, filteredResponses);

  await page.addStyleTag({ content: readFileSync(`${process.cwd()}/public/css/pim.css`, 'utf-8')})
  await page.evaluate(async () => await require('pim/init')());
  await page.evaluate(async () => await require('pim/user-context').initialize());
  await page.evaluate(async () => await require('pim/date-context').initialize());
  await page.evaluate(async () => await require('pim/init-translator').fetch());
  await page.evaluate(async () => await require('oro/init-layout')());

  await page.evaluate(() => {
    const FormBuilder = require('pim/form-builder');

    return FormBuilder.build('pim-product-index').then(form => {
      form.setElement(document.getElementById('app')).render();

      return form;
    });
  });

  return waitForLoading(page);
}

// Custom matchers for the product grid
expect.extend({
  filterToBeVisible: async (filterName, page) => {
    try {
      return {
        pass: (await page.$(`.filter-box .filter-item[data-name="${filterName}"]`)) !== null,
        message: () => `Filter "${filterName}" should be visible`
      }
    } catch (e) {
      return { pass: false }
    }
  },
  toBeFilterableByOperator: async (filterName, operator, page) => {
    try {
      const filter = await page.$(`.filter-item[data-name="${filterName}"]`);
      await filter.click();

      const operatorDropdown = await filter.$('.operator');
      await operatorDropdown.click()

      const operatorChoice = await getOperatorChoiceByLabel(filter, operator);
      await operatorChoice.click();

      const updateButton = await filter.$('button')
      await updateButton.click();

      await waitForLoading(page);

      return {
        pass: true,
        message: () => `Can't filter "${filterName}" by "${operator}"`
      }
    } catch (e) {
      return {
        pass: false,
        message: () => 'It didnt work'
      }
    }
  },
  toBeDisplayedOnTheProductGrid: async function(products, page) {
    await waitForLoading(page);
    const rowLabels = (await getProductRowLabels(page)).map(label => label.toLowerCase());

    return {
      pass: this.equals(rowLabels, products.map(product => product.toLowerCase())),
      message: () => `Expected to see ${products.join(', ')} on the grid`
    }
  }
})

module.exports = {
  loadProductGrid,
  mockFilteredResponse
}